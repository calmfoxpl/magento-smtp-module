<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Alert;

/**
 * The decision to speak, or not to. Three possibilities and no more: say a shop cannot send,
 * say it can again, or say nothing.
 */
final class Alert
{
    public const NONE = 'none';

    public const PROBLEM = 'problem';

    public const RECOVERY = 'recovery';

    private function __construct(
        public readonly string $kind,
        public readonly string $cause = '',
        /** A reminder about something already reported, rather than news. */
        public readonly bool $isReminder = false,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function problem(string $cause, bool $isReminder = false): self
    {
        return new self(self::PROBLEM, $cause, $isReminder);
    }

    public static function recovery(string $cause): self
    {
        return new self(self::RECOVERY, $cause);
    }

    public function shouldSpeak(): bool
    {
        return self::NONE !== $this->kind;
    }

    public function isProblem(): bool
    {
        return self::PROBLEM === $this->kind;
    }
}
