<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Health;

/**
 * Three states, and the difference between the middle two is the whole design.
 *
 * `warn` is what a single failure earns. Mail servers have bad minutes: a rate limit at noon, a
 * provider restarting a node, a connection that timed out once. A module that shouted at every
 * one of those would be muted inside a week, and then the day the API key is revoked nobody
 * would look. So a first failure is recorded and shown, and only a failure that persists — or
 * one that plainly needs a human, like a refused password — becomes `fail` and speaks up.
 */
final class Status
{
    /** Nothing has been checked yet, which is not the same as working. */
    public const UNKNOWN = 'unknown';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const ALL = [self::UNKNOWN, self::OK, self::WARN, self::FAIL];

    public static function isBroken(string $status): bool
    {
        return self::FAIL === $status;
    }

    public static function normalize(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return \in_array($value, self::ALL, true) ? $value : self::UNKNOWN;
    }
}
