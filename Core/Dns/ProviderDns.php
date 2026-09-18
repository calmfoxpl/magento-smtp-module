<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

use Calmfox\Smtp\Core\Provider\ProviderCatalog;

/**
 * What each provider expects a customer's domain to publish.
 *
 * Every value here was checked against live DNS rather than taken from memory, because the cost
 * of being wrong is asymmetric: a wrong include turns into "your domain does not authorise your
 * provider" shown to somebody whose setup is perfectly fine, and a warning that cries wolf is
 * worse than no warning. Three of the first guesses were wrong — SMTP2GO is `spf.smtp2go.com`
 * and not `_spf.smtp2go.com`, SparkPost is the bare `sparkpostmail.com`, and Resend has no
 * include of its own at all because it sends through Amazon.
 *
 * DKIM is deliberately thin. A selector can only be looked up if it is known in advance, and
 * most providers mint a random one per domain — Amazon and Postmark among them. Where that is
 * the case there is no selector here and no DKIM verdict at all, because the absence of a name
 * we guessed proves nothing about the shop. The four that are listed use a fixed selector, which
 * is documented and was confirmed in DNS.
 */
final class ProviderDns
{
    /**
     * Provider id => the SPF includes that authorise it. Several where a provider has regions or
     * a former name still in use.
     *
     * @var array<string, list<string>>
     */
    private const SPF_INCLUDES = [
        'brevo' => ['spf.brevo.com', 'spf.sendinblue.com'],
        'mailgun' => ['mailgun.org', '_spf.mailgun.org', '_spf.eu.mailgun.org'],
        'sendgrid' => ['sendgrid.net'],
        'postmark' => ['spf.mtasv.net'],
        'ses' => ['amazonses.com'],
        'mailjet' => ['spf.mailjet.com'],
        'smtp2go' => ['spf.smtp2go.com'],
        // Resend delivers through Amazon, so a domain set up for it authorises Amazon.
        'resend' => ['amazonses.com'],
        'sparkpost' => ['sparkpostmail.com'],
        'mailchimp' => ['spf.mandrillapp.com'],
        'elasticemail' => ['_spf.elasticemail.com'],
        'zoho' => ['zohomail.com', 'zoho.eu', 'zoho.com'],
        'gmail' => ['_spf.google.com'],
        'microsoft365' => ['spf.protection.outlook.com'],
    ];

    /**
     * Senders we recognise but have no preset for, by the include they publish.
     *
     * The point of this list is the suggestion, not the check: a shop whose domain already
     * authorises one of these is told which service that is and that it has to be filled in by
     * hand, which beats staring at a provider list that does not contain it. They are not
     * presets because a preset has to say what the service means by its two credential fields,
     * and that is not something to guess at — the hosts and the includes here were confirmed in
     * DNS, the credential semantics were not.
     *
     * @var array<string, string>
     */
    private const OTHER_SENDERS = [
        '_spf.emaillabs.net.pl' => 'EmailLabs',
        '_spf.getresponse.com' => 'GetResponse',
        'mx.ovh.com' => 'OVH',
        'mail.zendesk.com' => 'Zendesk',
    ];

    /**
     * Provider id => selectors it always uses. Empty, or absent, means the provider mints one
     * per domain and we must not conclude anything from a name not being there.
     *
     * @var array<string, list<string>>
     */
    private const DKIM_SELECTORS = [
        'gmail' => ['google'],
        'microsoft365' => ['selector1', 'selector2'],
        'sendgrid' => ['s1', 's2'],
        'mailjet' => ['mailjet'],
    ];

    /** @return list<string> */
    public static function spfIncludes(string $providerId): array
    {
        return self::SPF_INCLUDES[$providerId] ?? [];
    }

    /** @return list<string> */
    public static function dkimSelectors(string $providerId): array
    {
        return self::DKIM_SELECTORS[$providerId] ?? [];
    }

    /** Can anything be said about this provider's records at all? */
    public static function isKnown(string $providerId): bool
    {
        return [] !== self::spfIncludes($providerId);
    }

    /**
     * The provider an include belongs to, which is how a domain's own SPF can suggest what the
     * shop is already set up to send through.
     *
     * Amazon is the deliberate exception: `amazonses.com` is shared by Amazon's own customers
     * and by Resend's, so it resolves to Amazon and the panel says as much rather than guessing
     * between the two.
     */
    public static function providerForInclude(string $include): ?string
    {
        $include = mb_strtolower(trim($include, ' .'));

        foreach (self::SPF_INCLUDES as $providerId => $includes) {
            if ('resend' === $providerId) {
                continue;
            }
            if (\in_array($include, $includes, true)) {
                return $providerId;
            }
        }

        return null;
    }

    /** A service we recognise from its include but have no preset for. */
    public static function otherSenderForInclude(string $include): ?string
    {
        return self::OTHER_SENDERS[mb_strtolower(trim($include, ' .'))] ?? null;
    }

    /** Every provider in the catalogue whose records we can say something about. */
    public static function assertEveryEntryIsInTheCatalogue(): void
    {
        foreach (array_keys(self::SPF_INCLUDES) as $providerId) {
            if (!ProviderCatalog::has($providerId)) {
                throw new \LogicException(sprintf('ProviderDns knows "%s", which is not in the catalogue.', $providerId));
            }
        }
        foreach (array_keys(self::DKIM_SELECTORS) as $providerId) {
            if (!ProviderCatalog::has($providerId)) {
                throw new \LogicException(sprintf('ProviderDns has selectors for "%s", which is not in the catalogue.', $providerId));
            }
        }
    }
}
