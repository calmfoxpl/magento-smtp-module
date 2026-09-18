<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

use Calmfox\Smtp\Core\Settings\Issue;
use Calmfox\Smtp\Core\Settings\IssueCode;
use Calmfox\Smtp\Core\Settings\MailSettings;

/**
 * Asks the sender domain whether the provider the shop is configured to use is actually allowed
 * to send as it.
 *
 * This is the half of the module that the connection check cannot do. A refused password makes
 * itself known within the quarter hour; a domain that never authorised its new provider sends
 * perfectly happily for weeks while Gmail quietly files everything under spam, and the shop's
 * own test message — sent to a colleague at the same domain — arrives beautifully. That is the
 * failure this exists for.
 *
 * It follows one level of nesting, because agencies and hosting companies routinely put their
 * own record in the middle (`include:_spf.agency.pl` which in turn includes the provider), and
 * stopping at the first level would report a perfectly good setup as broken. It stops there
 * rather than recursing to the end for the same reason receivers do: RFC 7208 allows ten
 * lookups in total, and a checker that spends more than a receiver would is measuring something
 * the receiver never sees.
 */
final class DomainInspector
{
    public function __construct(private readonly Resolver $dns)
    {
    }

    public function inspect(MailSettings $settings): DomainVerdict
    {
        $domain = SenderDomain::of($settings);
        if (null === $domain) {
            return DomainVerdict::withoutASenderDomain();
        }

        try {
            $txt = $this->dns->txt($domain);
        } catch (LookupFailed) {
            return DomainVerdict::unreachable($domain);
        }

        $records = Spf::fromTxtRecords($txt);
        $issues = [];

        if ([] === $records) {
            $issues[] = Issue::error(IssueCode::SPF_MISSING, [$domain]);

            return DomainVerdict::of($domain, false, null, null, $this->dmarcPolicy($domain, $issues), 0, $issues);
        }
        if (\count($records) > 1) {
            // Not a nuance: a receiver evaluating two records treats the whole thing as a
            // permanent error and authorises nobody at all.
            $issues[] = Issue::error(IssueCode::SPF_MULTIPLE, [$domain]);
        }

        $spf = $records[0];
        $cost = $spf->lookupCost();

        if ($cost > Spf::LOOKUP_LIMIT) {
            $issues[] = Issue::error(IssueCode::SPF_LOOKUP_LIMIT, [$cost, Spf::LOOKUP_LIMIT]);
        }

        $qualifier = $spf->allQualifier();
        if ('+' === $qualifier) {
            $issues[] = Issue::error(IssueCode::SPF_ALL_PERMISSIVE, [$domain]);
        } elseif (null === $qualifier && null === $spf->redirect()) {
            $issues[] = Issue::warning(IssueCode::SPF_NO_ALL);
        }

        $expected = ProviderDns::spfIncludes($settings->providerId);
        $authorizes = null;
        if ([] !== $expected) {
            $authorizes = $this->authorizes($spf, $expected);
            if (!$authorizes) {
                $issues[] = Issue::error(IssueCode::SPF_DOES_NOT_AUTHORIZE_PROVIDER, [$domain, $expected[0]]);
            }
        }

        return DomainVerdict::of(
            $domain,
            true,
            $authorizes,
            $this->dkim($domain, $settings->providerId, $issues),
            $this->dmarcPolicy($domain, $issues),
            $cost,
            $issues,
            $this->otherSender($spf),
        );
    }

    /**
     * The suggestion side: what a domain's own records say the shop is set up to send through.
     *
     * Used when the settings are still empty, and only ever as a question. A domain can
     * authorise three services at once and the shop may mean any of them — or none, having just
     * decided to move.
     */
    public function suggest(string $domain): Suggestion
    {
        $submission = $this->submission($domain);

        try {
            $records = Spf::fromTxtRecords($this->dns->txt($domain));
        } catch (LookupFailed) {
            return $submission;
        }

        foreach ($records as $spf) {
            foreach ($spf->includes() as $include) {
                $providerId = ProviderDns::providerForInclude($include);
                if (null !== $providerId) {
                    return $submission->withProvider($providerId, Suggestion::FROM_SPF);
                }
            }
            foreach ($spf->includes() as $include) {
                $other = ProviderDns::otherSenderForInclude($include);
                if (null !== $other) {
                    return $submission->withOtherSender($other);
                }
            }
        }

        return $submission;
    }

    /**
     * A submission service published in DNS, per RFC 6186 and RFC 8314.
     *
     * Rare, and worth asking anyway: where it exists it is the domain's own answer rather than
     * our guess. Operators of mailboxes publish it — `wp.pl` says port 465, `gmail.com` says
     * 587 — while providers built for sending mostly do not.
     */
    private function submission(string $domain): Suggestion
    {
        foreach ([['_submissions._tcp.', true], ['_submission._tcp.', false]] as [$prefix, $implicitTls]) {
            try {
                $records = $this->dns->srv($prefix . $domain);
            } catch (LookupFailed) {
                continue;
            }

            usort($records, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

            foreach ($records as $record) {
                $target = rtrim($record['target'], '.');
                // RFC 2782 spells "this service is not offered here" as a single dot.
                if ('' === $target || '.' === $record['target']) {
                    continue;
                }

                return Suggestion::fromSrv($target, $record['port'], $implicitTls || 465 === $record['port']);
            }
        }

        return Suggestion::none();
    }

    /** @param list<string> $expected */
    private function authorizes(Spf $spf, array $expected): bool
    {
        foreach ($spf->includes() as $include) {
            if (\in_array($include, $expected, true)) {
                return true;
            }
        }

        $budget = Spf::LOOKUP_LIMIT;
        foreach (array_merge($spf->includes(), array_filter([$spf->redirect()])) as $include) {
            if ($budget <= 0) {
                return false;
            }
            --$budget;

            try {
                $nested = Spf::fromTxtRecords($this->dns->txt($include));
            } catch (LookupFailed) {
                continue;
            }

            foreach ($nested as $record) {
                foreach ($record->includes() as $deeper) {
                    if (\in_array($deeper, $expected, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param list<Issue> $issues
     *
     * Only asked where the provider uses a selector that is the same for everybody. Amazon and
     * Postmark mint one per domain, so the absence of any name we could guess would prove
     * nothing, and null here means "not checked" rather than "not there".
     */
    private function dkim(string $domain, string $providerId, array &$issues): ?bool
    {
        $selectors = ProviderDns::dkimSelectors($providerId);
        if ([] === $selectors) {
            return null;
        }

        foreach ($selectors as $selector) {
            try {
                if ([] !== $this->dns->txt($selector . '._domainkey.' . $domain)) {
                    return true;
                }
            } catch (LookupFailed) {
                return null;
            }
        }

        $issues[] = Issue::error(IssueCode::DKIM_MISSING_FOR_PROVIDER, [$domain, $selectors[0]]);

        return false;
    }

    /** @param list<Issue> $issues */
    private function dmarcPolicy(string $domain, array &$issues): ?string
    {
        try {
            $records = $this->dns->txt('_dmarc.' . $domain);
        } catch (LookupFailed) {
            return null;
        }

        foreach ($records as $record) {
            if (1 !== preg_match('/^\s*v=DMARC1\b/i', $record)) {
                continue;
            }
            if (1 !== preg_match('/\bp\s*=\s*(?<policy>none|quarantine|reject)/i', $record, $match)) {
                return null;
            }

            $policy = mb_strtolower($match['policy']);
            if ('none' === $policy) {
                $issues[] = Issue::warning(IssueCode::DMARC_MONITOR_ONLY, [$domain]);
            }

            return $policy;
        }

        $issues[] = Issue::warning(IssueCode::DMARC_MISSING, [$domain]);

        return null;
    }

    private function otherSender(Spf $spf): ?string
    {
        foreach ($spf->includes() as $include) {
            $other = ProviderDns::otherSenderForInclude($include);
            if (null !== $other) {
                return $other;
            }
        }

        return null;
    }
}
