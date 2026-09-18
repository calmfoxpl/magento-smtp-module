<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Probe;

/**
 * How far the conversation got. This is the most useful single fact in a failed check: the same
 * refusal means different things at the greeting and after the password, and an administrator
 * who is told "it failed while logging in" already knows which field to look at.
 */
final class Stage
{
    public const CONNECT = 'connect';

    public const GREETING = 'greeting';

    public const EHLO = 'ehlo';

    public const STARTTLS = 'starttls';

    public const HANDSHAKE = 'handshake';

    public const AUTH = 'auth';

    /** Everything we set out to check answered. */
    public const DONE = 'done';

    public const ALL = [self::CONNECT, self::GREETING, self::EHLO, self::STARTTLS, self::HANDSHAKE, self::AUTH, self::DONE];
}
