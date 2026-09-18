<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Health;

/**
 * How patient to be, and how often to repeat ourselves. All four are settings in the panel,
 * because a shop sending two messages a day and one sending two thousand an hour do not agree
 * on what "broken" means.
 */
final class Thresholds
{
    public function __construct(
        /** Consecutive failed checks before a transient problem is called a failure. */
        public readonly int $failedChecksBeforeAlert = 2,
        /** How long to stay quiet after warning about the same thing. Six hours by default. */
        public readonly int $repeatAfterSeconds = 21600,
        /** Real messages that have to fail before the shop is called broken. */
        public readonly int $sendFailuresBeforeAlert = 3,
        /** The window those failures have to fall inside, so an old one does not count for ever. */
        public readonly int $sendFailureWindowSeconds = 1800,
    ) {
    }

    public static function fromArray(array $values): self
    {
        return new self(
            max(1, (int) ($values['failed_checks'] ?? 2)),
            max(300, (int) ($values['repeat_after'] ?? 21600)),
            max(1, (int) ($values['send_failures'] ?? 3)),
            max(60, (int) ($values['send_window'] ?? 1800)),
        );
    }
}
