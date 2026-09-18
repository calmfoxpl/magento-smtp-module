<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Settings;

/**
 * Every complaint the resolver can make about the settings.
 *
 * The list is here, in one place, so that the panel can be tested for having a sentence ready
 * for each of them. A code with no sentence would reach an administrator as a blank warning,
 * which is the one thing worse than no warning at all.
 */
final class IssueCode
{
    /** Sending is switched off in Magento's own mail settings, so nothing we do here matters. */
    public const EMAIL_DISABLED_IN_MAGENTO = 'email_disabled_in_magento';

    public const HOST_MISSING = 'host_missing';

    public const PORT_INVALID = 'port_invalid';

    public const USERNAME_MISSING = 'username_missing';

    public const PASSWORD_MISSING = 'password_missing';

    /** Port 465 speaks TLS from the first byte; without encryption the server never answers. */
    public const ENCRYPTION_MISSING_ON_IMPLICIT_PORT = 'encryption_missing_on_implicit_port';

    /** TLS from the first byte on a STARTTLS port: the handshake meets a plain text greeting. */
    public const IMPLICIT_TLS_ON_STARTTLS_PORT = 'implicit_tls_on_starttls_port';

    /** STARTTLS on 465: the server is waiting for a handshake, we send it a word. */
    public const STARTTLS_ON_IMPLICIT_PORT = 'starttls_on_implicit_port';

    public const CREDENTIALS_WITHOUT_ENCRYPTION = 'credentials_without_encryption';

    /** Port 25 outbound is blocked by most hosting companies and by most home connections. */
    public const PORT_25 = 'port_25';

    public const CERTIFICATE_CHECK_OFF = 'certificate_check_off';

    public const FROM_EMAIL_INVALID = 'from_email_invalid';

    public const RETURN_PATH_INVALID = 'return_path_invalid';

    /** Magento's own SMTP settings point somewhere else, which is confusing to read later. */
    public const MAGENTO_SMTP_ALSO_CONFIGURED = 'magento_smtp_also_configured';

    // ── what the sender domain publishes ─────────────────────────────────────
    //
    // These are a different kind of complaint from the ones above: the shop can send, the
    // provider accepts the message, and it is the receiver who bins it. They never make the
    // health check say "broken", because it is not — they are the answer to "everything is
    // green and the mail still is not arriving", which is the one question the connection
    // check cannot answer.

    /** No address to judge: nothing tells us which domain the shop sends as. */
    public const SENDER_DOMAIN_UNKNOWN = 'sender_domain_unknown';

    /** DNS could not answer, so nothing was concluded either way. */
    public const DNS_UNAVAILABLE = 'dns_unavailable';

    public const SPF_MISSING = 'spf_missing';

    /** Two SPF records is a permanent error at the receiver: both are ignored. */
    public const SPF_MULTIPLE = 'spf_multiple';

    /** The domain's SPF does not list the provider the shop is configured to send through. */
    public const SPF_DOES_NOT_AUTHORIZE_PROVIDER = 'spf_does_not_authorize_provider';

    /** Over ten DNS lookups: receivers give up on the record and authorise nobody. */
    public const SPF_LOOKUP_LIMIT = 'spf_lookup_limit';

    /** `+all`: everybody in the world is authorised, which is worse than having no SPF. */
    public const SPF_ALL_PERMISSIVE = 'spf_all_permissive';

    /** No `all` at the end, so unlisted senders get no verdict rather than a soft fail. */
    public const SPF_NO_ALL = 'spf_no_all';

    public const DKIM_MISSING_FOR_PROVIDER = 'dkim_missing_for_provider';

    public const DMARC_MISSING = 'dmarc_missing';

    /** DMARC only watches (`p=none`), so a forged sender is reported and still delivered. */
    public const DMARC_MONITOR_ONLY = 'dmarc_monitor_only';

    public const ALL = [
        self::EMAIL_DISABLED_IN_MAGENTO,
        self::HOST_MISSING,
        self::PORT_INVALID,
        self::USERNAME_MISSING,
        self::PASSWORD_MISSING,
        self::ENCRYPTION_MISSING_ON_IMPLICIT_PORT,
        self::IMPLICIT_TLS_ON_STARTTLS_PORT,
        self::STARTTLS_ON_IMPLICIT_PORT,
        self::CREDENTIALS_WITHOUT_ENCRYPTION,
        self::PORT_25,
        self::CERTIFICATE_CHECK_OFF,
        self::FROM_EMAIL_INVALID,
        self::RETURN_PATH_INVALID,
        self::MAGENTO_SMTP_ALSO_CONFIGURED,
        self::SENDER_DOMAIN_UNKNOWN,
        self::DNS_UNAVAILABLE,
        self::SPF_MISSING,
        self::SPF_MULTIPLE,
        self::SPF_DOES_NOT_AUTHORIZE_PROVIDER,
        self::SPF_LOOKUP_LIMIT,
        self::SPF_ALL_PERMISSIVE,
        self::SPF_NO_ALL,
        self::DKIM_MISSING_FOR_PROVIDER,
        self::DMARC_MISSING,
        self::DMARC_MONITOR_ONLY,
    ];

    /**
     * The complaints that mean "the shop can send, and the mail may still not arrive".
     *
     * Kept as a list because the panel shows them apart from the rest and under a warning rather
     * than an alarm: mixing "cannot send" with "may not be delivered" would rob the first of its
     * meaning, and the first is the one somebody has to act on within the hour.
     */
    public const DELIVERABILITY = [
        self::SPF_MISSING,
        self::SPF_MULTIPLE,
        self::SPF_DOES_NOT_AUTHORIZE_PROVIDER,
        self::SPF_LOOKUP_LIMIT,
        self::SPF_ALL_PERMISSIVE,
        self::DKIM_MISSING_FOR_PROVIDER,
    ];
}
