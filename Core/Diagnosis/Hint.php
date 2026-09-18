<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Diagnosis;

/**
 * The sentence after the diagnosis: not what went wrong, but the thing that is usually wrong
 * when it goes wrong at this provider.
 *
 * A refused password means something different at every provider. At Amazon it is nearly always
 * AWS keys pasted where SMTP credentials belong; at Google it is the account password instead
 * of an App password; at Microsoft it is a switch that is off by default; at Mailgun it is a
 * domain in the European region dialled on the global host. Knowing the provider turns a dead
 * end into one thing to go and check.
 *
 * Hints are never certainties, and the panel wording keeps them that way — "most often", not
 * "the problem is".
 */
final class Hint
{
    public const SES_SMTP_CREDENTIALS = 'ses_smtp_credentials';

    public const SES_SANDBOX = 'ses_sandbox';

    public const GMAIL_APP_PASSWORD = 'gmail_app_password';

    public const MICROSOFT_SMTP_AUTH = 'microsoft_smtp_auth';

    public const SENDGRID_PINNED_LOGIN = 'sendgrid_pinned_login';

    public const POSTMARK_TOKEN = 'postmark_token';

    public const MAILGUN_REGION = 'mailgun_region';

    public const ZOHO_REGION = 'zoho_region';

    public const BREVO_SMTP_KEY = 'brevo_smtp_key';

    public const MAILJET_KEY_PAIR = 'mailjet_key_pair';

    /** Outbound SMTP ports closed by the hosting company: the commonest cause of a timeout. */
    public const PORT_BLOCKED = 'port_blocked';

    /** A mailbox asked to behave like a sending service, and hitting its daily limit. */
    public const MAILBOX_LIMIT = 'mailbox_limit';

    public const CERTIFICATE = 'certificate';

    public const WRONG_PORT_FOR_ENCRYPTION = 'wrong_port_for_encryption';

    public const ALL = [
        self::SES_SMTP_CREDENTIALS,
        self::SES_SANDBOX,
        self::GMAIL_APP_PASSWORD,
        self::MICROSOFT_SMTP_AUTH,
        self::SENDGRID_PINNED_LOGIN,
        self::POSTMARK_TOKEN,
        self::MAILGUN_REGION,
        self::ZOHO_REGION,
        self::BREVO_SMTP_KEY,
        self::MAILJET_KEY_PAIR,
        self::PORT_BLOCKED,
        self::MAILBOX_LIMIT,
        self::CERTIFICATE,
        self::WRONG_PORT_FOR_ENCRYPTION,
    ];
}
