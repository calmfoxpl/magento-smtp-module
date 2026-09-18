<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Health;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Probe\ProbeResult;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Core\Settings\ResolvedSettings;

/**
 * Turns one observation into a verdict, given everything observed before it.
 *
 * Three things can be observed: a check of the connection, a real message that went out, and a
 * real message that did not. The last two matter most — a shop whose order confirmations are
 * failing is broken whatever a connection test says — and a successful send is the strongest
 * evidence there is, so it clears the record.
 *
 * All of it is arithmetic over timestamps, with the clock passed in. That is what makes "stays
 * quiet for a single timeout, speaks up for a refused password, forgets it all when the password
 * is corrected" a set of tests rather than a hope.
 */
final class HealthEvaluator
{
    public function __construct(private readonly Thresholds $thresholds = new Thresholds())
    {
    }

    /**
     * Settings that cannot produce a connection — no host, no password, sending switched off in
     * Magento — are a failure, and one that plainly needs a human. There is nothing to dial and
     * no point counting to two about it.
     */
    public function afterUnusableSettings(HealthState $previous, ResolvedSettings $resolved, int $now): HealthState
    {
        $fresh = $this->resetIfSettingsChanged($previous, $resolved->settings, $now);
        $code = $resolved->errors()[0]->code ?? '';

        return $fresh->with([
            'status' => Status::FAIL,
            'cause' => Cause::NOT_CONFIGURED,
            'stage' => '',
            'reply_code' => null,
            'reply' => $code,
            'checked_at' => $now,
            'duration_ms' => 0,
            'consecutive_failures' => $fresh->consecutiveFailures + 1,
            'failing_since' => $fresh->failingSince ?? $now,
            'fingerprint' => $resolved->settings->fingerprint(),
        ]);
    }

    public function afterProbe(HealthState $previous, ProbeResult $result, MailSettings $settings, int $now): HealthState
    {
        $fresh = $this->resetIfSettingsChanged($previous, $settings, $now);

        if ($result->ok) {
            return $fresh->with([
                'status' => Status::OK,
                'cause' => Cause::OK,
                'stage' => $result->stage,
                'reply_code' => null,
                'reply' => '',
                'checked_at' => $now,
                'duration_ms' => $result->milliseconds,
                'consecutive_failures' => 0,
                'failing_since' => null,
                'last_ok_at' => $now,
                'fingerprint' => $settings->fingerprint(),
            ]);
        }

        $failures = $fresh->consecutiveFailures + 1;

        return $fresh->with([
            'status' => $this->statusForFailure($result->cause, $failures),
            'cause' => $result->cause,
            'stage' => $result->stage,
            'reply_code' => $result->replyCode,
            'reply' => $result->reply,
            'checked_at' => $now,
            'duration_ms' => $result->milliseconds,
            'consecutive_failures' => $failures,
            'failing_since' => $fresh->failingSince ?? $now,
            'fingerprint' => $settings->fingerprint(),
        ]);
    }

    /**
     * A message the shop actually tried to send and could not.
     *
     * Counted inside a window rather than for ever: three failures in half an hour is a broken
     * shop, three failures spread over a fortnight is three bad addresses.
     */
    public function afterSendFailure(HealthState $previous, string $cause, string $message, int $now): HealthState
    {
        $withinWindow = null !== $previous->sendFailuresSince
            && $now - $previous->sendFailuresSince <= $this->thresholds->sendFailureWindowSeconds;

        $failures = $withinWindow ? $previous->sendFailures + 1 : 1;
        $enough = $failures >= $this->thresholds->sendFailuresBeforeAlert || Cause::needsAHuman($cause);

        return $previous->with([
            'status' => $enough ? Status::FAIL : Status::WARN,
            'cause' => $cause,
            'send_failures' => $failures,
            'send_failures_since' => $withinWindow ? $previous->sendFailuresSince : $now,
            'last_send_cause' => $cause,
            'last_send_error' => $message,
            'failing_since' => $previous->failingSince ?? $now,
        ]);
    }

    /**
     * A message that went out. Nothing argues with that, so the record is cleared — including a
     * failure the connection check is still convinced of, because the check is an inference and
     * this is the thing itself.
     */
    public function afterSendSuccess(HealthState $previous, int $now): HealthState
    {
        return $previous->with([
            'status' => Status::OK,
            'cause' => Cause::OK,
            'send_failures' => 0,
            'send_failures_since' => null,
            'last_send_cause' => null,
            'last_send_error' => '',
            'last_sent_at' => $now,
            'consecutive_failures' => 0,
            'failing_since' => null,
            'last_ok_at' => $now,
        ]);
    }

    /**
     * A failure that needs somebody to change something is a failure straight away; one that
     * might pass on its own has to repeat before we call it one.
     */
    private function statusForFailure(string $cause, int $consecutiveFailures): string
    {
        if (Cause::needsAHuman($cause)) {
            return Status::FAIL;
        }

        return $consecutiveFailures >= $this->thresholds->failedChecksBeforeAlert ? Status::FAIL : Status::WARN;
    }

    /**
     * Somebody has changed the settings, so everything we knew is about a different server.
     *
     * Without this, an administrator who has just pasted the new API key would still be reading
     * a red box about the old one, and would reasonably conclude the module is lying to them.
     * The notification is forgotten too, so a problem that comes back is reported again.
     */
    private function resetIfSettingsChanged(HealthState $previous, MailSettings $settings, int $now): HealthState
    {
        $fingerprint = $settings->fingerprint();
        if ('' === $previous->fingerprint || $previous->fingerprint === $fingerprint) {
            return $previous;
        }

        // What we told an administrator is kept, though. They are owed the sentence that says
        // sending works again, and that sentence only gets written if we remember warning them.
        return new HealthState(
            status: Status::UNKNOWN,
            cause: Cause::UNKNOWN,
            checkedAt: 0,
            fingerprint: $fingerprint,
            lastSentAt: $previous->lastSentAt,
            lastOkAt: $previous->lastOkAt,
            notifiedCause: $previous->notifiedCause,
            notifiedAt: $previous->notifiedAt,
        );
    }
}
