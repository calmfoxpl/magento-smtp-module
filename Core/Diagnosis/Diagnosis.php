<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Diagnosis;

use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Core\Settings\MailSettings;

/**
 * Reads what the operating system, the TLS layer or the provider said, and names one cause.
 *
 * All three speak differently about the same handful of problems, and none of them speaks to a
 * shopkeeper. `php_network_getaddresses: getaddrinfo failed` is a typo in the host name;
 * `Connection refused` is the wrong port; a silent two-second wait is a hosting company that
 * closed port 587 outbound. Matching on the words as well as the error numbers is deliberate:
 * the numbers differ between macOS and Linux, while these phrases have been stable for decades.
 */
final class Diagnosis
{
    /** Connection refused, on Linux and on BSD. */
    private const REFUSED = [61, 111];

    /** Timed out. */
    private const TIMED_OUT = [60, 110];

    /** No route to host, network unreachable. */
    private const UNREACHABLE = [51, 65, 101, 113];

    /** What a failed connection was really about. */
    public static function fromConnectionError(int $errno, string $message): string
    {
        $text = mb_strtolower($message);

        if (self::mentions($text, ['getaddrinfo', 'name or service not known', 'nodename nor servname', 'no such host', 'temporary failure in name resolution'])) {
            return Cause::DNS_FAILURE;
        }
        if (self::certificateWords($text)) {
            return Cause::CERTIFICATE_REJECTED;
        }
        if (self::tlsWords($text)) {
            return Cause::TLS_HANDSHAKE_FAILED;
        }
        if (\in_array($errno, self::REFUSED, true) || self::mentions($text, ['connection refused'])) {
            return Cause::CONNECT_REFUSED;
        }
        if (\in_array($errno, self::TIMED_OUT, true) || self::mentions($text, ['timed out', 'timeout'])) {
            return Cause::CONNECT_TIMEOUT;
        }
        if (\in_array($errno, self::UNREACHABLE, true) || self::mentions($text, ['unreachable'])) {
            return Cause::NETWORK_UNREACHABLE;
        }
        if (self::mentions($text, ['connection reset', 'broken pipe', 'connection closed'])) {
            return Cause::PROTOCOL_ERROR;
        }

        return Cause::UNKNOWN;
    }

    /**
     * What a message that would not go out was really about.
     *
     * This is the other half of the health check, and in practice the more valuable one: a live
     * failure while sending an order confirmation is the ground truth, and a provider will often
     * say more in a rejection than it ever says to a connection test.
     */
    public static function fromSendFailure(string $message): string
    {
        $text = mb_strtolower($message);

        if (self::mentions($text, ['could not open socket', 'unable to connect', 'failed to connect'])) {
            return self::fromConnectionError(0, $message);
        }
        if (self::certificateWords($text)) {
            return Cause::CERTIFICATE_REJECTED;
        }
        if (self::tlsWords($text)) {
            return Cause::TLS_HANDSHAKE_FAILED;
        }
        if (self::mentions($text, ['authentication', 'username and password', 'not accepted', '5.7.8', '5.7.0', 'invalid login', 'bad credentials', 'auth failed'])) {
            return Cause::AUTH_REJECTED;
        }
        if (self::mentions($text, ['too many', 'rate limit', 'throttl', 'try again later', '4.7.0', 'quota'])) {
            return Cause::SERVER_BUSY;
        }
        if (self::mentions($text, ['not verified', 'not authorized', 'sender address', 'recipient', 'relay access denied', 'blocked', 'unverified'])) {
            return Cause::MESSAGE_REJECTED;
        }
        if (self::mentions($text, ['timed out', 'timeout'])) {
            return Cause::CONNECT_TIMEOUT;
        }

        return Cause::UNKNOWN;
    }

    /**
     * The one thing most worth checking, given who we are talking to.
     *
     * Only ever one: a list of five possibilities is a way of admitting we do not know, and a
     * shopkeeper reading it will check none of them.
     */
    public static function hint(string $cause, MailSettings $settings): ?string
    {
        if (Cause::CONNECT_TIMEOUT === $cause) {
            return Hint::PORT_BLOCKED;
        }
        if (Cause::CERTIFICATE_REJECTED === $cause) {
            return Hint::CERTIFICATE;
        }
        if (\in_array($cause, [Cause::TLS_HANDSHAKE_FAILED, Cause::NOT_SMTP, Cause::STARTTLS_UNSUPPORTED], true)) {
            return self::portMismatch($settings) ? Hint::WRONG_PORT_FOR_ENCRYPTION : ($cause === Cause::NOT_SMTP ? Hint::WRONG_PORT_FOR_ENCRYPTION : Hint::CERTIFICATE);
        }

        if (\in_array($cause, [Cause::AUTH_REJECTED, Cause::AUTH_UNSUPPORTED], true)) {
            return match ($settings->providerId) {
                'ses' => Hint::SES_SMTP_CREDENTIALS,
                'gmail' => Hint::GMAIL_APP_PASSWORD,
                'microsoft365' => Hint::MICROSOFT_SMTP_AUTH,
                'sendgrid' => Hint::SENDGRID_PINNED_LOGIN,
                'postmark' => Hint::POSTMARK_TOKEN,
                'mailgun' => Hint::MAILGUN_REGION,
                'zoho' => Hint::ZOHO_REGION,
                'brevo' => Hint::BREVO_SMTP_KEY,
                'mailjet' => Hint::MAILJET_KEY_PAIR,
                default => null,
            };
        }

        if (Cause::MESSAGE_REJECTED === $cause) {
            return match ($settings->providerId) {
                'ses' => Hint::SES_SANDBOX,
                'gmail', 'microsoft365' => Hint::MAILBOX_LIMIT,
                default => null,
            };
        }

        if (Cause::SERVER_BUSY === $cause && \in_array($settings->providerId, ['gmail', 'microsoft365'], true)) {
            return Hint::MAILBOX_LIMIT;
        }

        return null;
    }

    /** Encryption and port disagreeing is the one misconfiguration that looks like a dead server. */
    private static function portMismatch(MailSettings $settings): bool
    {
        $implicitPort = \in_array($settings->port, Encryption::IMPLICIT_TLS_PORTS, true);

        return ($implicitPort && Encryption::SSL !== $settings->encryption)
            || (!$implicitPort && Encryption::SSL === $settings->encryption);
    }

    private static function certificateWords(string $text): bool
    {
        return self::mentions($text, [
            'certificate verify failed',
            'self signed',
            'self-signed',
            'unable to get local issuer',
            'certificate has expired',
            'peer certificate',
            'name mismatch',
            'hostname mismatch',
        ]);
    }

    private static function tlsWords(string $text): bool
    {
        return self::mentions($text, [
            'handshake',
            'wrong version number',
            'sslv3',
            'unknown protocol',
            'ssl routines',
            'tls',
            'ssl operation failed',
            'no shared cipher',
        ]);
    }

    /** @param list<string> $needles */
    private static function mentions(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
