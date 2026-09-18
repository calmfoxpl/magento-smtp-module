<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Health;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Health\HealthEvaluator;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Core\Health\Thresholds;
use Calmfox\Smtp\Core\Probe\ProbeResult;
use Calmfox\Smtp\Core\Probe\Stage;
use Calmfox\Smtp\Core\Settings\IssueCode;
use Calmfox\Smtp\Core\Settings\Issue;
use Calmfox\Smtp\Core\Settings\ResolvedSettings;
use Calmfox\Smtp\Tests\Support\Settings;
use PHPUnit\Framework\TestCase;

final class HealthEvaluatorTest extends TestCase
{
    private const NOON = 1758196800;

    public function testAWorkingCheckIsRecordedAsWorking(): void
    {
        $settings = Settings::make();
        $state = (new HealthEvaluator())->afterProbe(
            new HealthState(),
            ProbeResult::succeeded(['AUTH'], 'LOGIN', 120, true, true),
            $settings,
            self::NOON,
        );

        self::assertSame(Status::OK, $state->status);
        self::assertSame(Cause::OK, $state->cause);
        self::assertSame(self::NOON, $state->lastOkAt);
        self::assertSame(0, $state->consecutiveFailures);
        self::assertSame(120, $state->durationMs);
        self::assertSame($settings->fingerprint(), $state->fingerprint);
    }

    /**
     * The rule the whole design rests on: one bad minute is not an emergency. A shop whose
     * provider rate limited it at noon gets a note in the panel, not a phone call.
     */
    public function testASingleTransientFailureIsOnlyAWarning(): void
    {
        $state = (new HealthEvaluator())->afterProbe(
            new HealthState(),
            ProbeResult::failed(Stage::GREETING, Cause::SERVER_BUSY, 421, '421 too many connections'),
            Settings::make(),
            self::NOON,
        );

        self::assertSame(Status::WARN, $state->status);
        self::assertSame(1, $state->consecutiveFailures);
        self::assertSame(self::NOON, $state->failingSince);
    }

    public function testATransientFailureThatKeepsHappeningBecomesAFailure(): void
    {
        $evaluator = new HealthEvaluator();
        $settings = Settings::make();
        $busy = ProbeResult::failed(Stage::GREETING, Cause::SERVER_BUSY, 421, 'busy');

        $first = $evaluator->afterProbe(new HealthState(), $busy, $settings, self::NOON);
        $second = $evaluator->afterProbe($first, $busy, $settings, self::NOON + 900);

        self::assertSame(Status::WARN, $first->status);
        self::assertSame(Status::FAIL, $second->status);
        self::assertSame(2, $second->consecutiveFailures);
        // The clock on the problem runs from the first failure, not the one that tipped it over.
        self::assertSame(self::NOON, $second->failingSince);
    }

    /** A refused password will not fix itself, so there is nothing to be patient about. */
    public function testAFailureThatNeedsAHumanIsAFailureImmediately(): void
    {
        $state = (new HealthEvaluator())->afterProbe(
            new HealthState(),
            ProbeResult::failed(Stage::AUTH, Cause::AUTH_REJECTED, 535, '535 not accepted'),
            Settings::make(),
            self::NOON,
        );

        self::assertSame(Status::FAIL, $state->status);
        self::assertSame(1, $state->consecutiveFailures);
    }

    public function testSettingsThatCannotProduceAConnectionAreAFailureWithoutDialling(): void
    {
        $resolved = new ResolvedSettings(Settings::make(), [Issue::error(IssueCode::HOST_MISSING)]);

        $state = (new HealthEvaluator())->afterUnusableSettings(new HealthState(), $resolved, self::NOON);

        self::assertSame(Status::FAIL, $state->status);
        self::assertSame(Cause::NOT_CONFIGURED, $state->cause);
        self::assertSame(IssueCode::HOST_MISSING, $state->reply);
    }

    /**
     * Someone fixing the password should see the red box go away, not argue with a cached
     * verdict about a credential that no longer exists.
     */
    public function testChangedSettingsWipeTheOldVerdict(): void
    {
        $evaluator = new HealthEvaluator();
        $broken = $evaluator->afterProbe(
            new HealthState(),
            ProbeResult::failed(Stage::AUTH, Cause::AUTH_REJECTED, 535, '535 not accepted'),
            Settings::make(password: 'the old key'),
            self::NOON,
        )->notified(Cause::AUTH_REJECTED, self::NOON);

        $afterEdit = $evaluator->afterProbe(
            $broken,
            ProbeResult::succeeded([], 'LOGIN', 90, true, true),
            Settings::make(password: 'the new key'),
            self::NOON + 60,
        );

        self::assertSame(Status::OK, $afterEdit->status);
        self::assertSame(0, $afterEdit->consecutiveFailures);
        // But that they were told is remembered, so they also get told it works again.
        self::assertSame(Cause::AUTH_REJECTED, $afterEdit->notifiedCause);
    }

    /** Three failures in half an hour is a broken shop; three over a fortnight is not. */
    public function testRealSendFailuresAreCountedInsideAWindow(): void
    {
        $evaluator = new HealthEvaluator();
        $state = new HealthState(status: Status::OK, fingerprint: Settings::make()->fingerprint());

        $state = $evaluator->afterSendFailure($state, Cause::SERVER_BUSY, '421 busy', self::NOON);
        self::assertSame(Status::WARN, $state->status);
        self::assertSame(1, $state->sendFailures);

        $state = $evaluator->afterSendFailure($state, Cause::SERVER_BUSY, '421 busy', self::NOON + 60);
        self::assertSame(Status::WARN, $state->status);

        $state = $evaluator->afterSendFailure($state, Cause::SERVER_BUSY, '421 busy', self::NOON + 120);
        self::assertSame(Status::FAIL, $state->status);
        self::assertSame(3, $state->sendFailures);
    }

    public function testAnOldSendFailureDoesNotCountTowardsANewOne(): void
    {
        $evaluator = new HealthEvaluator(new Thresholds(sendFailureWindowSeconds: 1800));
        $state = $evaluator->afterSendFailure(new HealthState(), Cause::SERVER_BUSY, '421', self::NOON);

        $muchLater = $evaluator->afterSendFailure($state, Cause::SERVER_BUSY, '421', self::NOON + 7200);

        self::assertSame(1, $muchLater->sendFailures);
        self::assertSame(Status::WARN, $muchLater->status);
    }

    /** A message that actually went out beats any inference a connection test can make. */
    public function testASuccessfulSendClearsEverything(): void
    {
        $evaluator = new HealthEvaluator();
        $broken = $evaluator->afterProbe(
            new HealthState(),
            ProbeResult::failed(Stage::AUTH, Cause::AUTH_REJECTED, 535, 'no'),
            Settings::make(),
            self::NOON,
        );

        $sent = $evaluator->afterSendSuccess($broken, self::NOON + 30);

        self::assertSame(Status::OK, $sent->status);
        self::assertSame(Cause::OK, $sent->cause);
        self::assertSame(0, $sent->sendFailures);
        self::assertSame(self::NOON + 30, $sent->lastSentAt);
    }

    /** The record survives a round trip through the database unchanged. */
    public function testTheStateSurvivesBeingStored(): void
    {
        $state = (new HealthEvaluator())->afterProbe(
            new HealthState(),
            ProbeResult::failed(Stage::AUTH, Cause::AUTH_REJECTED, 535, '535 nope'),
            Settings::make(),
            self::NOON,
        )->notified(Cause::AUTH_REJECTED, self::NOON);

        self::assertSame($state->toArray(), HealthState::fromArray($state->toArray())->toArray());
    }

    public function testAFreshInstallIsUnknownRatherThanHealthy(): void
    {
        $state = new HealthState();

        self::assertSame(Status::UNKNOWN, $state->status);
        self::assertFalse($state->isBroken());
        self::assertFalse($state->wasEverChecked());
    }
}
