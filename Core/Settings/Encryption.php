<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Settings;

/**
 * How the connection is protected. The three values are spelled the way Magento spells them in
 * `system/smtp/ssl`, so a shop that already filled Magento's own mail settings can be read
 * without a translation table: an empty string, `tls` (STARTTLS on a plain port, which is what
 * every provider on port 587 expects) and `ssl` (TLS from the first byte, port 465).
 */
final class Encryption
{
    /** No protection at all: the password travels in the clear, so we refuse to send one. */
    public const NONE = '';

    /** Plain connection upgraded with STARTTLS. The usual choice, port 587. */
    public const TLS = 'tls';

    /** TLS from the first byte, no STARTTLS. Port 465. */
    public const SSL = 'ssl';

    public const ALL = [self::NONE, self::TLS, self::SSL];

    /** Ports that mean "this connection is protected", used to catch a settings mismatch. */
    public const IMPLICIT_TLS_PORTS = [465];

    public const STARTTLS_PORTS = [587, 2525, 25];

    public static function normalize(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return \in_array($value, [self::TLS, self::SSL], true) ? $value : self::NONE;
    }

    /** The scheme PHP's stream functions want in front of the host. */
    public static function streamPrefix(string $encryption): string
    {
        return self::SSL === $encryption ? 'ssl://' : '';
    }
}
