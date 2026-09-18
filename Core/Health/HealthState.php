<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Health;

use Calmfox\Smtp\Core\Diagnosis\Cause;

/**
 * Everything the module remembers about whether the shop can send, as one immutable record.
 *
 * It is kept as a flat array in one row, on purpose: the health check has to be readable from a
 * console command, from the dashboard, from a cron job and from an outside monitor, and none of
 * those should have to know how the others store things. Times are Unix timestamps for the same
 * reason — no time zone to get wrong between a cron run and a page view.
 *
 * `notifiedCause` is the quiet hero of this class. Without it the module either tells an
 * administrator about the same dead password every fifteen minutes until they filter us out, or
 * tells them once and never mentions it again when the cause changes underneath.
 */
final class HealthState
{
    public function __construct(
        public readonly string $status = Status::UNKNOWN,
        public readonly string $cause = Cause::UNKNOWN,
        public readonly string $stage = '',
        public readonly ?int $replyCode = null,
        public readonly string $reply = '',
        public readonly int $checkedAt = 0,
        public readonly int $durationMs = 0,
        public readonly int $consecutiveFailures = 0,
        public readonly ?int $failingSince = null,
        public readonly ?int $lastOkAt = null,
        public readonly string $fingerprint = '',
        public readonly int $sendFailures = 0,
        public readonly ?int $sendFailuresSince = null,
        public readonly ?string $lastSendCause = null,
        public readonly string $lastSendError = '',
        public readonly ?int $lastSentAt = null,
        public readonly ?string $notifiedCause = null,
        public readonly ?int $notifiedAt = null,
    ) {
    }

    public function isBroken(): bool
    {
        return Status::isBroken($this->status);
    }

    public function wasEverChecked(): bool
    {
        return $this->checkedAt > 0;
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            Status::normalize($row['status'] ?? null),
            (string) ($row['cause'] ?? Cause::UNKNOWN),
            (string) ($row['stage'] ?? ''),
            isset($row['reply_code']) && '' !== $row['reply_code'] ? (int) $row['reply_code'] : null,
            (string) ($row['reply'] ?? ''),
            (int) ($row['checked_at'] ?? 0),
            (int) ($row['duration_ms'] ?? 0),
            (int) ($row['consecutive_failures'] ?? 0),
            self::nullableInt($row['failing_since'] ?? null),
            self::nullableInt($row['last_ok_at'] ?? null),
            (string) ($row['fingerprint'] ?? ''),
            (int) ($row['send_failures'] ?? 0),
            self::nullableInt($row['send_failures_since'] ?? null),
            isset($row['last_send_cause']) && '' !== $row['last_send_cause'] ? (string) $row['last_send_cause'] : null,
            (string) ($row['last_send_error'] ?? ''),
            self::nullableInt($row['last_sent_at'] ?? null),
            isset($row['notified_cause']) && '' !== $row['notified_cause'] ? (string) $row['notified_cause'] : null,
            self::nullableInt($row['notified_at'] ?? null),
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'cause' => $this->cause,
            'stage' => $this->stage,
            'reply_code' => $this->replyCode,
            'reply' => $this->reply,
            'checked_at' => $this->checkedAt,
            'duration_ms' => $this->durationMs,
            'consecutive_failures' => $this->consecutiveFailures,
            'failing_since' => $this->failingSince,
            'last_ok_at' => $this->lastOkAt,
            'fingerprint' => $this->fingerprint,
            'send_failures' => $this->sendFailures,
            'send_failures_since' => $this->sendFailuresSince,
            'last_send_cause' => $this->lastSendCause,
            'last_send_error' => $this->lastSendError,
            'last_sent_at' => $this->lastSentAt,
            'notified_cause' => $this->notifiedCause,
            'notified_at' => $this->notifiedAt,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * A copy with some fields changed. Spelled with the storage keys so that the evaluator and
     * the row in the database never drift apart.
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    /** The record of an administrator having been told, which is what keeps us from repeating. */
    public function notified(string $cause, int $at): self
    {
        return $this->with(['notified_cause' => $cause, 'notified_at' => $at]);
    }

    public function forgotten(): self
    {
        return $this->with(['notified_cause' => null, 'notified_at' => null]);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }
}
