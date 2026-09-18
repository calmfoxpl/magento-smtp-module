<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Settings;

/**
 * How we prove who we are. Providers advertise what they accept in the EHLO reply, and the
 * honest default is to take their word for it: `auto` picks the strongest method both sides
 * know, which keeps a shop working when a provider retires a mechanism. The explicit values
 * exist for the rarer case of a server that advertises more than it actually accepts.
 */
final class AuthMethod
{
    /** Pick from what the server advertises: CRAM-MD5, then LOGIN, then PLAIN. */
    public const AUTO = 'auto';

    public const PLAIN = 'plain';

    public const LOGIN = 'login';

    public const CRAM_MD5 = 'crammd5';

    /** An open relay on the local network, or a provider that authenticates by IP address. */
    public const NONE = 'none';

    public const ALL = [self::AUTO, self::PLAIN, self::LOGIN, self::CRAM_MD5, self::NONE];

    /** Strongest first, which is the order `auto` tries. */
    public const PREFERENCE = [self::CRAM_MD5, self::LOGIN, self::PLAIN];

    public static function normalize(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return \in_array($value, self::ALL, true) ? $value : self::AUTO;
    }

    /** The token as it appears in the server's `250-AUTH` line. */
    public static function keyword(string $method): string
    {
        return match ($method) {
            self::CRAM_MD5 => 'CRAM-MD5',
            self::LOGIN => 'LOGIN',
            self::PLAIN => 'PLAIN',
            default => '',
        };
    }
}
