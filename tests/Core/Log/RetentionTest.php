<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Log;

use Calmfox\Smtp\Core\Log\Retention;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    private const NOON = 1758196800;

    public function testTheCutoffIsTheConfiguredNumberOfDaysAgo(): void
    {
        self::assertSame(self::NOON - 7 * 86400, Retention::cutoff(self::NOON, 7));
    }

    /** A log of outgoing mail is personal data; "for ever" is not an option we offer. */
    public function testAnAbsurdRetentionIsCappedAndAnEmptyOneIsTheDefault(): void
    {
        self::assertSame(Retention::MAX_DAYS, Retention::days(100000));
        self::assertSame(Retention::DEFAULT_DAYS, Retention::days(''));
        self::assertSame(Retention::DEFAULT_DAYS, Retention::days(0));
        self::assertSame(1, Retention::days(1));
    }

    public function testTheBodyIsOnlyStoredWhenItWasAskedForAndIsSmallEnough(): void
    {
        self::assertTrue(Retention::shouldStoreBody(true, 2048));
        self::assertFalse(Retention::shouldStoreBody(false, 2048));
        self::assertFalse(Retention::shouldStoreBody(true, Retention::MAX_BODY_BYTES + 1));
        self::assertFalse(Retention::shouldStoreBody(true, 0));
    }
}
