<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Dns;

use Calmfox\Smtp\Core\Dns\Spf;
use PHPUnit\Framework\TestCase;

final class SpfTest extends TestCase
{
    public function testItReadsTheSendersADomainDefersTo(): void
    {
        $spf = Spf::parse('v=spf1 include:_spf.google.com include:_spf.emaillabs.net.pl ~all');

        self::assertSame(['_spf.google.com', '_spf.emaillabs.net.pl'], $spf->includes());
        self::assertSame('~', $spf->allQualifier());
        self::assertNull($spf->redirect());
    }

    /** An `all` with no qualifier means `+all`, which is the dangerous one. */
    public function testABareAllIsReadAsPermissive(): void
    {
        self::assertSame('+', Spf::parse('v=spf1 ip4:10.0.0.1 all')->allQualifier());
        self::assertSame('+', Spf::parse('v=spf1 +all')->allQualifier());
        self::assertSame('-', Spf::parse('v=spf1 -all')->allQualifier());
        self::assertNull(Spf::parse('v=spf1 include:example.com')->allQualifier());
    }

    public function testARedirectIsNotAnInclude(): void
    {
        $spf = Spf::parse('v=spf1 redirect=_spf.example.com');

        self::assertSame([], $spf->includes());
        self::assertSame('_spf.example.com', $spf->redirect());
    }

    /**
     * The count that decides whether a receiver bothers with the record at all. Ten is the
     * limit; `ip4` and `all` are free, everything that needs another query is not.
     */
    public function testTheLookupCostIsWhatAReceiverWouldSpend(): void
    {
        self::assertSame(0, Spf::parse('v=spf1 ip4:10.0.0.0/8 ip6:::1 -all')->lookupCost());
        self::assertSame(3, Spf::parse('v=spf1 include:a.com include:b.com mx -all')->lookupCost());
        self::assertSame(2, Spf::parse('v=spf1 a exists:%{i}._spf.example.com ~all')->lookupCost());
        self::assertSame(1, Spf::parse('v=spf1 redirect=example.com')->lookupCost());
    }

    public function testOnlyAnSpfRecordIsTakenForOne(): void
    {
        self::assertTrue(Spf::looksLikeSpf('v=spf1 -all'));
        self::assertTrue(Spf::looksLikeSpf('  V=SPF1 include:example.com ~all'));
        self::assertFalse(Spf::looksLikeSpf('google-site-verification=abc'));
        self::assertFalse(Spf::looksLikeSpf('v=DMARC1; p=none'));
        // A TXT record that merely mentions it is not one.
        self::assertFalse(Spf::looksLikeSpf('this is not v=spf1'));
    }

    /** A domain's TXT records are a mixed bag; only the SPF ones are ours. */
    public function testItPicksTheSpfRecordsOutOfATxtSet(): void
    {
        $records = Spf::fromTxtRecords([
            'google-site-verification=abc',
            'v=spf1 include:sendgrid.net -all',
            'MS=ms12345',
        ]);

        self::assertCount(1, $records);
        self::assertSame(['sendgrid.net'], $records[0]->includes());
    }

    public function testCaseAndWhitespaceDoNotChangeTheAnswer(): void
    {
        $spf = Spf::parse("v=spf1   INCLUDE:SendGrid.NET    -ALL");

        self::assertSame(['sendgrid.net'], $spf->includes());
        self::assertSame('-', $spf->allQualifier());
    }
}
