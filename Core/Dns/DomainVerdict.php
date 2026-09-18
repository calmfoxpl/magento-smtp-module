<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

use Calmfox\Smtp\Core\Settings\Issue;
use Calmfox\Smtp\Core\Settings\IssueCode;

/**
 * What the sender domain publishes, and what that means for whether the mail arrives.
 *
 * Three states are kept apart on purpose, because collapsing them is how a check starts lying:
 * a record that is missing, a record that is there and does not authorise the provider, and a
 * question that could not be asked at all — DNS unreachable, or a provider whose records we
 * have no way to predict. The last one is reported as "not checked", never as "fine".
 */
final class DomainVerdict
{
    /**
     * @param list<Issue> $issues
     */
    private function __construct(
        public readonly ?string $domain,
        public readonly bool $asked,
        public readonly bool $spfFound,
        public readonly ?bool $spfAuthorizesProvider,
        public readonly ?bool $dkimFound,
        public readonly ?string $dmarcPolicy,
        public readonly int $spfLookupCost,
        public readonly array $issues = [],
        /** A sender the domain authorises that we recognise but have no preset for. */
        public readonly ?string $otherSender = null,
    ) {
    }

    /** Nothing tells us which domain to judge, so nothing was looked up. */
    public static function withoutASenderDomain(): self
    {
        return new self(null, false, false, null, null, null, 0, [Issue::warning(IssueCode::SENDER_DOMAIN_UNKNOWN)]);
    }

    public static function unreachable(string $domain): self
    {
        return new self($domain, false, false, null, null, null, 0, [Issue::warning(IssueCode::DNS_UNAVAILABLE)]);
    }

    /** @param list<Issue> $issues */
    public static function of(
        string $domain,
        bool $spfFound,
        ?bool $spfAuthorizesProvider,
        ?bool $dkimFound,
        ?string $dmarcPolicy,
        int $spfLookupCost,
        array $issues,
        ?string $otherSender = null,
    ): self {
        return new self($domain, true, $spfFound, $spfAuthorizesProvider, $dkimFound, $dmarcPolicy, $spfLookupCost, $issues, $otherSender);
    }

    /**
     * Is there something here that will cost the shop delivered mail?
     *
     * A missing DMARC record is not on that list, and neither is a record without a trailing
     * `all`. Plenty of shops send perfectly well without either, and a warning that fires for
     * them would be ignored by the time it fires for something that matters.
     */
    public function isDeliverabilityAtRisk(): bool
    {
        foreach ($this->issues as $issue) {
            if (\in_array($issue->code, IssueCode::DELIVERABILITY, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (Issue $issue): string => $issue->code, $this->issues);
    }

    public function has(string $code): bool
    {
        return \in_array($code, $this->codes(), true);
    }

    /**
     * The whole verdict as plain values, complete enough to be stored and read back.
     *
     * It has to round-trip, because the panel is not allowed to make DNS queries: the resolver
     * has no timeout worth the name, and an admin page that waits five seconds on a slow
     * nameserver is a worse bug than the one this feature fixes. So the lookups happen on a
     * button, on cron and in the console, the answer is kept, and every page reads the kept one.
     * The cache is keyed by the settings it was reached under, so changing the provider throws
     * it away rather than showing yesterday's verdict about a provider nobody uses now.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'asked' => $this->asked,
            'spf_found' => $this->spfFound,
            'spf_authorizes_provider' => $this->spfAuthorizesProvider,
            'dkim_found' => $this->dkimFound,
            'dmarc_policy' => $this->dmarcPolicy,
            'spf_lookup_cost' => $this->spfLookupCost,
            'other_sender' => $this->otherSender,
            'issues' => array_map(
                static fn (Issue $issue): array => [
                    'code' => $issue->code,
                    'severity' => $issue->severity,
                    'params' => $issue->params,
                ],
                $this->issues,
            ),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $issues = [];
        foreach (\is_array($row['issues'] ?? null) ? $row['issues'] : [] as $issue) {
            if (!\is_array($issue) || !isset($issue['code'])) {
                continue;
            }
            $params = \is_array($issue['params'] ?? null) ? array_values($issue['params']) : [];
            $issues[] = Issue::ERROR === ($issue['severity'] ?? Issue::WARNING)
                ? Issue::error((string) $issue['code'], $params)
                : Issue::warning((string) $issue['code'], $params);
        }

        return new self(
            null === ($row['domain'] ?? null) ? null : (string) $row['domain'],
            (bool) ($row['asked'] ?? false),
            (bool) ($row['spf_found'] ?? false),
            self::nullableBool($row['spf_authorizes_provider'] ?? null),
            self::nullableBool($row['dkim_found'] ?? null),
            null === ($row['dmarc_policy'] ?? null) ? null : (string) $row['dmarc_policy'],
            (int) ($row['spf_lookup_cost'] ?? 0),
            $issues,
            null === ($row['other_sender'] ?? null) ? null : (string) $row['other_sender'],
        );
    }

    private static function nullableBool(mixed $value): ?bool
    {
        return null === $value ? null : (bool) $value;
    }
}
