<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Log;

use Calmfox\Smtp\Core\Log\MessageSummary;
use PHPUnit\Framework\TestCase;

final class MessageSummaryTest extends TestCase
{
    private const MESSAGE = "From: Shop <shop@example.com>\r\n"
        . "To: Anna Kowalska <anna@example.com>, bob@example.com\r\n"
        . "Cc: warehouse@example.com\r\n"
        . "Subject: Your order 000000123\r\n"
        . "Content-Type: text/html; charset=utf-8\r\n"
        . "\r\n"
        . '<p>Thank you</p>';

    public function testItReadsWhatALogRowNeeds(): void
    {
        $summary = MessageSummary::fromRaw(self::MESSAGE);

        self::assertSame('shop@example.com', $summary->from);
        self::assertSame(['anna@example.com', 'bob@example.com', 'warehouse@example.com'], $summary->recipients);
        self::assertSame('Your order 000000123', $summary->subject);
        self::assertSame(\strlen(self::MESSAGE), $summary->bytes);
    }

    /** Any subject with a Polish letter in it arrives encoded, and a log of base64 is useless. */
    public function testAnEncodedSubjectIsDecoded(): void
    {
        $subject = 'Potwierdzenie zamówienia nr 123';
        $raw = "From: shop@example.com\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n\r\nbody";

        self::assertSame($subject, MessageSummary::fromRaw($raw)->subject);
    }

    public function testAQuotedPrintableSubjectIsDecodedToo(): void
    {
        $raw = "Subject: =?UTF-8?Q?Zam=C3=B3wienie_nr_7?=\r\n\r\nbody";

        self::assertSame('Zamówienie nr 7', MessageSummary::fromRaw($raw)->subject);
    }

    /** Long subjects arrive folded across lines and have to be put back together. */
    public function testAFoldedHeaderIsJoined(): void
    {
        $raw = "Subject: Your order has been\r\n dispatched today\r\nFrom: shop@example.com\r\n\r\nbody";

        self::assertSame('Your order has been dispatched today', MessageSummary::fromRaw($raw)->subject);
    }

    public function testADisplayNameWithACommaInItIsNotTwoRecipients(): void
    {
        $raw = "To: \"Kowalska, Anna\" <anna@example.com>, bob@example.com\r\n\r\nbody";

        self::assertSame(['anna@example.com', 'bob@example.com'], MessageSummary::fromRaw($raw)->recipients);
    }

    public function testTheSameAddressTwiceIsOneRecipient(): void
    {
        $raw = "To: anna@example.com\r\nCc: anna@example.com\r\n\r\nbody";

        self::assertSame(['anna@example.com'], MessageSummary::fromRaw($raw)->recipients);
    }

    public function testTheBodyIsNotMistakenForHeaders(): void
    {
        $raw = "Subject: Real subject\r\n\r\nSubject: not a header\r\nTo: nobody@example.com";

        $summary = MessageSummary::fromRaw($raw);

        self::assertSame('Real subject', $summary->subject);
        self::assertSame([], $summary->recipients);
    }

    public function testAMessageWithNothingUsefulInItDoesNotBreak(): void
    {
        $summary = MessageSummary::fromRaw('');

        self::assertSame('', $summary->from);
        self::assertSame([], $summary->recipients);
        self::assertSame('', $summary->subject);
        self::assertSame(0, $summary->bytes);
    }

    public function testTheColumnsAreShortEnoughForTheGrid(): void
    {
        $raw = "Subject: " . str_repeat('very long ', 60) . "\r\nTo: " . implode(', ', array_map(
            static fn (int $i): string => sprintf('person%d@example.com', $i),
            range(1, 12),
        )) . "\r\n\r\nbody";

        $summary = MessageSummary::fromRaw($raw);

        self::assertLessThanOrEqual(255, mb_strlen($summary->subjectForColumn()));
        self::assertLessThanOrEqual(255, mb_strlen($summary->recipientsForColumn()));
        self::assertStringContainsString('(+9)', $summary->recipientsForColumn());
    }
}
