<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Alert;

use Calmfox\Smtp\Core\Alert\Alert;
use Calmfox\Smtp\Core\Alert\AlertPolicy;
use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Core\Health\Thresholds;
use PHPUnit\Framework\TestCase;

final class AlertPolicyTest extends TestCase
{
    private const NOON = 1758196800;

    private static function broken(string $cause = Cause::AUTH_REJECTED): HealthState
    {
        return new HealthState(status: Status::FAIL, cause: $cause, checkedAt: self::NOON);
    }

    public function testANewFailureIsReported(): void
    {
        $alert = (new AlertPolicy())->decide(self::broken(), self::NOON);

        self::assertTrue($alert->isProblem());
        self::assertFalse($alert->isReminder);
        self::assertSame(Cause::AUTH_REJECTED, $alert->cause);
    }

    /** Told once, not every fifteen minutes. That is the difference between a warning and noise. */
    public function testTheSameFailureIsNotReportedTwice(): void
    {
        $told = self::broken()->notified(Cause::AUTH_REJECTED, self::NOON);

        $alert = (new AlertPolicy())->decide($told, self::NOON + 900);

        self::assertSame(Alert::NONE, $alert->kind);
    }

    /** "The password is refused" and "the port is closed" are different jobs for whoever fixes it. */
    public function testAFailureThatChangesItsReasonIsReportedAgain(): void
    {
        $told = self::broken(Cause::CONNECT_TIMEOUT)->notified(Cause::AUTH_REJECTED, self::NOON);

        $alert = (new AlertPolicy())->decide($told, self::NOON + 60);

        self::assertTrue($alert->isProblem());
        self::assertSame(Cause::CONNECT_TIMEOUT, $alert->cause);
        self::assertFalse($alert->isReminder);
    }

    /** A shop that has not sent an order confirmation since Tuesday deserves a second knock. */
    public function testALongSilenceEarnsAReminder(): void
    {
        $policy = new AlertPolicy(new Thresholds(repeatAfterSeconds: 21600));
        $told = self::broken()->notified(Cause::AUTH_REJECTED, self::NOON);

        self::assertSame(Alert::NONE, $policy->decide($told, self::NOON + 21599)->kind);

        $reminder = $policy->decide($told, self::NOON + 21600);
        self::assertTrue($reminder->isProblem());
        self::assertTrue($reminder->isReminder);
    }

    /** A warning nobody hears the end of is a warning ignored next time. */
    public function testRecoveryIsReportedToWhoeverWasWarned(): void
    {
        $recovered = (new HealthState(status: Status::OK, cause: Cause::OK, checkedAt: self::NOON))
            ->notified(Cause::AUTH_REJECTED, self::NOON - 3600);

        $alert = (new AlertPolicy())->decide($recovered, self::NOON);

        self::assertSame(Alert::RECOVERY, $alert->kind);
        self::assertSame(Cause::AUTH_REJECTED, $alert->cause);
    }

    public function testNobodyIsToldItIsFixedIfTheyWereNeverToldItWasBroken(): void
    {
        $fine = new HealthState(status: Status::OK, cause: Cause::OK, checkedAt: self::NOON);

        self::assertFalse((new AlertPolicy())->decide($fine, self::NOON)->shouldSpeak());
    }

    /** `warn` lives in the panel. It never reaches an inbox. */
    public function testAWarningIsNeverSentAnywhere(): void
    {
        $warning = new HealthState(status: Status::WARN, cause: Cause::CONNECT_TIMEOUT, checkedAt: self::NOON);

        self::assertFalse((new AlertPolicy())->decide($warning, self::NOON)->shouldSpeak());
    }

    public function testAnUncheckedShopIsNotAnAlarm(): void
    {
        self::assertFalse((new AlertPolicy())->decide(new HealthState(), self::NOON)->shouldSpeak());
    }
}
