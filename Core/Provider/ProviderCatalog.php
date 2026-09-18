<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Provider;

use Calmfox\Smtp\Core\Settings\AuthMethod;
use Calmfox\Smtp\Core\Settings\Encryption;

/**
 * The providers a Magento shop is actually sent through, and one entry for "a server of my own".
 *
 * Two of them are deliberately marked as a poor fit for a shop rather than left out: Gmail and
 * Microsoft 365. People pick them because the address already exists, and they are the two that
 * reliably fall over on the first busy day, when a mailbox meant for correspondence is asked to
 * send several hundred order confirmations. Saying so in the panel is kinder than letting the
 * daily limit say it.
 *
 * Ports are the ones the providers document today: 587 with STARTTLS almost everywhere, which
 * is also the port least likely to be blocked outbound by a hosting company.
 */
final class ProviderCatalog
{
    public const CUSTOM = 'custom';

    /** @var array<string, Provider>|null */
    private static ?array $providers = null;

    /** @return array<string, Provider> keyed by id, in the order they are offered */
    public static function all(): array
    {
        return self::$providers ??= self::build();
    }

    public static function has(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /** An unknown id resolves to the custom preset, so a removed provider cannot break a shop. */
    public static function get(string $id): Provider
    {
        return self::all()[$id] ?? self::all()[self::CUSTOM];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, Provider> */
    private static function build(): array
    {
        $providers = [
            new Provider(
                id: self::CUSTOM,
                label: 'Custom SMTP server',
                host: '',
                port: 587,
                usernameHint: 'Whatever the server expects as the login, usually a full e-mail address.',
                passwordHint: 'The password for that login. Leave both fields empty for a relay that authenticates by IP address.',
            ),
            new Provider(
                id: 'brevo',
                label: 'Brevo',
                host: 'smtp-relay.brevo.com',
                port: 587,
                usernameHint: 'The SMTP login from SMTP & API → SMTP, which is an e-mail address and not always the one you sign in with.',
                passwordHint: 'An SMTP key generated on the same page. The account password will not work.',
                docsUrl: 'https://help.brevo.com/hc/en-us/articles/209462765',
            ),
            new Provider(
                id: 'mailgun',
                label: 'Mailgun',
                host: 'smtp.mailgun.org',
                port: 587,
                usernameHint: 'The SMTP login of the sending domain, usually postmaster@your-domain.',
                passwordHint: 'The SMTP password shown for that domain. Resetting it there breaks sending here until it is copied again.',
                caveat: 'A domain created in the European region only answers on the European host.',
                hostVariants: ['Europe' => 'smtp.eu.mailgun.org'],
                docsUrl: 'https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/#smtp-relay',
            ),
            new Provider(
                id: 'sendgrid',
                label: 'SendGrid',
                host: 'smtp.sendgrid.net',
                port: 587,
                usernameHint: 'Always the literal word apikey. It is filled in for you and cannot be changed.',
                passwordHint: 'An API key with the Mail Send permission. It is shown once, when it is created.',
                pinnedUsername: 'apikey',
                docsUrl: 'https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api',
            ),
            new Provider(
                id: 'postmark',
                label: 'Postmark',
                host: 'smtp.postmarkapp.com',
                port: 587,
                usernameHint: 'The Server API token, the same value as the password. It is filled in for you.',
                passwordHint: 'The Server API token from the server\'s API Tokens tab.',
                caveat: 'Postmark keeps transactional and bulk messages on separate streams; order e-mail belongs on the transactional one.',
                tokenInBothFields: true,
                docsUrl: 'https://postmarkapp.com/developer/user-guide/send-email-with-smtp',
            ),
            new Provider(
                id: 'ses',
                label: 'Amazon SES',
                host: 'email-smtp.eu-west-1.amazonaws.com',
                port: 587,
                usernameHint: 'An SES SMTP user name. These are created under SMTP settings and are not the same thing as an AWS access key.',
                passwordHint: 'The SMTP password issued with that user name, which is shown only once.',
                caveat: 'Pick the host of the region the identity was verified in, and remember that a new account is in the sandbox until Amazon lifts it: it can only write to verified addresses.',
                hostVariants: [
                    'Ireland' => 'email-smtp.eu-west-1.amazonaws.com',
                    'Frankfurt' => 'email-smtp.eu-central-1.amazonaws.com',
                    'North Virginia' => 'email-smtp.us-east-1.amazonaws.com',
                    'Oregon' => 'email-smtp.us-west-2.amazonaws.com',
                ],
                docsUrl: 'https://docs.aws.amazon.com/ses/latest/dg/send-email-smtp.html',
            ),
            new Provider(
                id: 'mailjet',
                label: 'Mailjet',
                host: 'in-v3.mailjet.com',
                port: 587,
                usernameHint: 'The API key from the account settings.',
                passwordHint: 'The secret key that belongs to that API key.',
                docsUrl: 'https://dev.mailjet.com/smtp-relay/configuration/',
            ),
            new Provider(
                id: 'smtp2go',
                label: 'SMTP2GO',
                host: 'mail.smtp2go.com',
                port: 587,
                usernameHint: 'An SMTP user created in the dashboard, not the address you sign in with.',
                passwordHint: 'The password set for that SMTP user.',
                caveat: 'If port 587 is blocked outbound, 2525 answers the same way.',
                docsUrl: 'https://support.smtp2go.com/hc/en-gb/articles/223087667',
            ),
            new Provider(
                id: 'resend',
                label: 'Resend',
                host: 'smtp.resend.com',
                port: 587,
                usernameHint: 'Always the literal word resend. It is filled in for you and cannot be changed.',
                passwordHint: 'An API key from the dashboard.',
                pinnedUsername: 'resend',
                docsUrl: 'https://resend.com/docs/send-with-smtp',
            ),
            new Provider(
                id: 'sparkpost',
                label: 'SparkPost',
                host: 'smtp.sparkpostmail.com',
                port: 587,
                usernameHint: 'Always the literal word SMTP_Injection. It is filled in for you and cannot be changed.',
                passwordHint: 'An API key with the Send via SMTP permission.',
                pinnedUsername: 'SMTP_Injection',
                hostVariants: ['Europe' => 'smtp.eu.sparkpostmail.com'],
                docsUrl: 'https://developers.sparkpost.com/api/smtp/',
            ),
            new Provider(
                id: 'mailchimp',
                label: 'Mailchimp Transactional',
                host: 'smtp.mandrillapp.com',
                port: 587,
                usernameHint: 'The e-mail address of the account, or any Mandrill user name.',
                passwordHint: 'An API key from the SMTP & API Info page.',
                docsUrl: 'https://mailchimp.com/developer/transactional/docs/smtp-integration/',
            ),
            new Provider(
                id: 'elasticemail',
                label: 'Elastic Email',
                host: 'smtp.elasticemail.com',
                port: 2525,
                usernameHint: 'The e-mail address of the account.',
                passwordHint: 'An SMTP credential generated in the account, not the password you sign in with.',
                docsUrl: 'https://elasticemail.com/developers/smtp',
            ),
            new Provider(
                id: 'zoho',
                label: 'Zoho Mail',
                host: 'smtp.zoho.com',
                port: 587,
                usernameHint: 'The full address of the mailbox.',
                passwordHint: 'An application-specific password, which is what Zoho issues once two-factor authentication is on.',
                caveat: 'Pick the host of the data centre the account lives in; the addresses are not interchangeable.',
                hostVariants: ['Europe' => 'smtp.zoho.eu', 'India' => 'smtp.zoho.in', 'Australia' => 'smtp.zoho.com.au'],
                docsUrl: 'https://www.zoho.com/mail/help/zoho-smtp.html',
            ),
            new Provider(
                id: 'gmail',
                label: 'Gmail and Google Workspace',
                host: 'smtp.gmail.com',
                port: 587,
                authMethod: AuthMethod::LOGIN,
                usernameHint: 'The full address of the mailbox.',
                passwordHint: 'An App password, not the account password. Google only issues one once two-step verification is on; the spaces it shows may be typed or left out.',
                caveat: 'A mailbox is not a sending service: Google stops a free account at around 500 messages a day and a Workspace one at around 2000, and counts every recipient. Fine for a small shop, a bad surprise for a busy one.',
                docsUrl: 'https://support.google.com/a/answer/176600',
            ),
            new Provider(
                id: 'microsoft365',
                label: 'Microsoft 365 and Outlook',
                host: 'smtp.office365.com',
                port: 587,
                authMethod: AuthMethod::LOGIN,
                usernameHint: 'The full address of the mailbox, which has to be the address messages are sent from.',
                passwordHint: 'The password of that mailbox, or an app password where multi-factor authentication is on.',
                caveat: 'SMTP AUTH is off by default and has to be enabled for the mailbox in the Microsoft 365 admin centre. Microsoft also throttles at around 30 messages a minute and 10 000 a day, and is retiring password-based SMTP: for order e-mail a provider built for sending is the safer home.',
                docsUrl: 'https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365',
            ),
        ];

        $keyed = [];
        foreach ($providers as $provider) {
            $keyed[$provider->id] = $provider;
        }

        return $keyed;
    }

    /** Every host and every encryption in the catalog has to be one we can actually dial. */
    public static function assertSane(): void
    {
        foreach (self::all() as $provider) {
            if (!\in_array($provider->encryption, Encryption::ALL, true)) {
                throw new \LogicException(sprintf('Provider "%s" has an unknown encryption.', $provider->id));
            }
            if (!\in_array($provider->authMethod, AuthMethod::ALL, true)) {
                throw new \LogicException(sprintf('Provider "%s" has an unknown authentication method.', $provider->id));
            }
        }
    }
}
