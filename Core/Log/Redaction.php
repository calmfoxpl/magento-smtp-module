<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Log;

/**
 * Keeps credentials out of everything we store or send on.
 *
 * This module writes to three places a credential must never reach: the log table, the alert
 * that goes to a webhook, and `var/log`. The risk is not carelessness in one obvious spot — the
 * password is never written deliberately — it is the second-hand copies. A failed SMTP
 * conversation quoted into a log entry contains the base64 line we sent. A provider's error
 * message sometimes echoes the login. A support e-mail with the settings in it is one copy-paste
 * from a password in a ticketing system.
 *
 * So redaction is applied at the boundary, to whole strings, rather than trusted to each caller.
 */
final class Redaction
{
    public const MASK = '***';

    /**
     * @param list<string> $secrets values that must not survive, typically the password
     */
    public static function apply(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            $secret = trim($secret);
            // A short "secret" would mask half the sentence and tell us nothing; four characters
            // is short enough to still catch anything worth calling a credential.
            if (mb_strlen($secret) < 4) {
                continue;
            }
            $text = str_replace($secret, self::MASK, $text);
            $text = str_replace(base64_encode($secret), self::MASK, $text);
        }

        // The wire itself: everything after an AUTH verb is a credential, whatever it encodes.
        $text = (string) preg_replace('/\bAUTH\s+(PLAIN|LOGIN|CRAM-MD5|XOAUTH2)\b[^\r\n]*/i', 'AUTH $1 ' . self::MASK, $text);
        $text = (string) preg_replace('/^(Authorization|X-Api-Key|X-Auth-Token)\s*:\s*.*/im', '$1: ' . self::MASK, $text);

        // A bare base64 line is what LOGIN sends the user name and the password on.
        return (string) preg_replace('/^[A-Za-z0-9+\/]{16,}={0,2}$/m', self::MASK, $text);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function applyToArray(array $data, array $secrets = []): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $data[$key] = self::looksLikeACredentialField((string) $key) ? self::MASK : self::apply($value, $secrets);
            } elseif (\is_array($value)) {
                $data[$key] = self::applyToArray($value, $secrets);
            }
        }

        return $data;
    }

    private static function looksLikeACredentialField(string $key): bool
    {
        return (bool) preg_match('/pass|secret|token|api[_-]?key|credential/i', $key);
    }
}
