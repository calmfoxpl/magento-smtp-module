<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Log;

/**
 * How long the log is kept, and how much of a message goes into it.
 *
 * Both answers are about restraint. A shop's outgoing mail contains names, addresses, order
 * contents and the occasional password reset link, so keeping all of it for ever is a liability
 * dressed up as a feature: it is personal data in a table nobody remembers is there. The
 * defaults are therefore short, storing the body is a deliberate choice, and a resend is only
 * possible while the body is still there — which the panel says plainly rather than offering a
 * button that fails.
 */
final class Retention
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 365;

    /** Bigger than this and the body is not stored: a log is not a place for attachments. */
    public const MAX_BODY_BYTES = 262144;

    public static function days(mixed $configured): int
    {
        $days = (int) $configured;
        if ($days < 1) {
            return self::DEFAULT_DAYS;
        }

        return min($days, self::MAX_DAYS);
    }

    /** Entries created at or before this moment are past keeping. */
    public static function cutoff(int $now, mixed $configuredDays): int
    {
        return $now - self::days($configuredDays) * 86400;
    }

    public static function shouldStoreBody(bool $configured, int $bytes): bool
    {
        return $configured && $bytes > 0 && $bytes <= self::MAX_BODY_BYTES;
    }
}
