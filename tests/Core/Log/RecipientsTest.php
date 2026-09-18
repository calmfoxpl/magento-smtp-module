<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Log;

use Calmfox\Smtp\Core\Log\Recipients;
use PHPUnit\Framework\TestCase;

final class RecipientsTest extends TestCase
{
    public function testAFewAddressesAreShownInFull(): void
    {
        self::assertSame(
            'a@example.com, b@example.com',
            Recipients::format(['a@example.com', ' b@example.com ']),
        );
    }

    /** One row per message, honest about the nine people it went to. */
    public function testALongListIsCountedRatherThanCutOff(): void
    {
        $addresses = array_map(static fn (int $i): string => sprintf('person%d@example.com', $i), range(1, 9));

        self::assertSame(
            'person1@example.com, person2@example.com, person3@example.com (+6)',
            Recipients::format($addresses),
        );
    }

    public function testItFitsInItsColumn(): void
    {
        $addresses = [str_repeat('a', 300) . '@example.com', str_repeat('b', 300) . '@example.com'];

        self::assertLessThanOrEqual(Recipients::COLUMN_LIMIT, mb_strlen(Recipients::format($addresses)));
    }

    public function testNoRecipientsIsEmptyRatherThanAWordAboutNothing(): void
    {
        self::assertSame('', Recipients::format([]));
        self::assertSame('', Recipients::format(['', '  ']));
        self::assertNull(Recipients::first(['', ' ']));
        self::assertSame('a@example.com', Recipients::first(['', 'a@example.com']));
    }
}
