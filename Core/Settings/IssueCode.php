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
    ];
}
