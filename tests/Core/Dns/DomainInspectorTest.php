<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Dns;

use Calmfox\Smtp\Core\Dns\DomainInspector;
use Calmfox\Smtp\Core\Dns\Spf;
use Calmfox\Smtp\Core\Dns\Suggestion;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Core\Settings\IssueCode;
use Calmfox\Smtp\Tests\Support\Settings;
use Calmfox\Smtp\Tests\Support\Zone;
use PHPUnit\Framework\TestCase;

final class DomainInspectorTest extends TestCase
{
    public function testADomainThatAuthorisesItsProviderIsLeftAlone(): void
    {
        $zone = new Zone(
            txt: [
                'sklep.pl' => ['v=spf1 include:sendgrid.net -all'],
                's1._domainkey.sklep.pl' => ['v=DKIM1; k=rsa; p=MIGf'],
                '_dmarc.sklep.pl' => ['v=DMARC1; p=reject; rua=mailto:dmarc@sklep.pl'],
            ],
        );

        $verdict = (new DomainInspector($zone))->inspect(
            Settings::make(providerId: 'sendgrid', username: 'apikey', password: 'SG.key', fromEmail: 'zamowienia@sklep.pl'),
        );

        self::assertSame('sklep.pl', $verdict->domain);
        self::assertTrue($verdict->spfFound);
        self::assertTrue($verdict->spfAuthorizesProvider);
        self::assertTrue($verdict->dkimFound);
        self::assertSame('reject', $verdict->dmarcPolicy);
        self::assertFalse($verdict->isDeliverabilityAtRisk());
        self::assertSame([], $verdict->issues);
    }

    /**
     * The failure this whole feature exists for: everything green, the provider accepts every
     * message, and receivers bin them because the domain never authorised the new provider.
     */
    public function testADomainThatDoesNotAuthoriseItsProviderIsReported(): void
    {
        $zone = new Zone(txt: [
            'sklep.pl' => ['v=spf1 include:_spf.google.com ~all'],
            '_dmarc.sklep.pl' => ['v=DMARC1; p=quarantine'],
        ]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'brevo', fromEmail: 'zamowienia@sklep.pl'));

        self::assertFalse($verdict->spfAuthorizesProvider);
        self::assertTrue($verdict->has(IssueCode::SPF_DOES_NOT_AUTHORIZE_PROVIDER));
        self::assertTrue($verdict->isDeliverabilityAtRisk());
    }

    /**
     * Agencies and hosting companies put their own record in the middle, so the provider is one
     * level further down. Stopping at the first level would call a good setup broken.
     */
    public function testAProviderReachedThroughAnAgencysRecordStillCounts(): void
    {
        $zone = new Zone(txt: [
            'sklep.pl' => ['v=spf1 include:_spf.agencja.pl -all'],
            '_spf.agencja.pl' => ['v=spf1 include:spf.brevo.com include:_spf.google.com ~all'],
        ]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'brevo', fromEmail: 'zamowienia@sklep.pl'));

        self::assertTrue($verdict->spfAuthorizesProvider);
        self::assertFalse($verdict->has(IssueCode::SPF_DOES_NOT_AUTHORIZE_PROVIDER));
    }

    public function testARedirectIsFollowedTheWayAReceiverWould(): void
    {
        $zone = new Zone(txt: [
            'sklep.pl' => ['v=spf1 redirect=_spf.hosting.pl'],
            '_spf.hosting.pl' => ['v=spf1 include:sendgrid.net -all'],
        ]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'sendgrid', fromEmail: 'zamowienia@sklep.pl'));

        self::assertTrue($verdict->spfAuthorizesProvider);
    }

    public function testNoSpfAtAllIsTheWorstCase(): void
    {
        $verdict = (new DomainInspector(new Zone()))->inspect(Settings::make(providerId: 'brevo', fromEmail: 'zamowienia@sklep.pl'));

        self::assertFalse($verdict->spfFound);
        self::assertTrue($verdict->has(IssueCode::SPF_MISSING));
        self::assertTrue($verdict->has(IssueCode::DMARC_MISSING));
        self::assertTrue($verdict->isDeliverabilityAtRisk());
    }

    /** Two records is a permanent error at the receiver: both are thrown away. */
    public function testTwoSpfRecordsAreReported(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => [
            'v=spf1 include:sendgrid.net -all',
            'v=spf1 include:spf.brevo.com -all',
        ]]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'sendgrid', fromEmail: 'zamowienia@sklep.pl'));

        self::assertTrue($verdict->has(IssueCode::SPF_MULTIPLE));
        self::assertTrue($verdict->isDeliverabilityAtRisk());
    }

    public function testAnSpfThatAuthorisesTheWholeInternetIsReported(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => ['v=spf1 include:sendgrid.net +all']]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'sendgrid', fromEmail: 'zamowienia@sklep.pl'));

        self::assertTrue($verdict->has(IssueCode::SPF_ALL_PERMISSIVE));
        self::assertTrue($verdict->isDeliverabilityAtRisk());
    }

    /** Over ten lookups and the record is ignored wholesale, however sensible it looks. */
    public function testAnSpfOverTheLookupLimitIsReported(): void
    {
        $includes = [];
        for ($i = 1; $i <= 11; ++$i) {
            $includes[] = sprintf('include:spf%d.example.com', $i);
        }
        $zone = new Zone(txt: ['sklep.pl' => ['v=spf1 include:sendgrid.net ' . implode(' ', $includes) . ' -all']]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'sendgrid', fromEmail: 'zamowienia@sklep.pl'));

        self::assertGreaterThan(Spf::LOOKUP_LIMIT, $verdict->spfLookupCost);
        self::assertTrue($verdict->has(IssueCode::SPF_LOOKUP_LIMIT));
    }

    /** "We could not ask" is never reported as "nothing is wrong". */
    public function testDnsThatCannotAnswerIsAdmittedRatherThanGuessed(): void
    {
        $zone = new Zone(unreachable: ['sklep.pl']);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'brevo', fromEmail: 'zamowienia@sklep.pl'));

        self::assertFalse($verdict->asked);
        self::assertTrue($verdict->has(IssueCode::DNS_UNAVAILABLE));
        self::assertNull($verdict->spfAuthorizesProvider);
        self::assertFalse($verdict->isDeliverabilityAtRisk());
    }

    public function testWithNothingToJudgeNothingIsLookedUp(): void
    {
        $verdict = (new DomainInspector(new Zone()))->inspect(
            Settings::make(username: 'apikey', password: 'SG.key'),
        );

        self::assertNull($verdict->domain);
        self::assertTrue($verdict->has(IssueCode::SENDER_DOMAIN_UNKNOWN));
        self::assertFalse($verdict->isDeliverabilityAtRisk());
    }

    /** For a server of one's own there is nothing to expect, so nothing is claimed. */
    public function testACustomServerGetsNoVerdictOnItsProvider(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => ['v=spf1 ip4:10.0.0.1 -all']]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'custom', fromEmail: 'zamowienia@sklep.pl'));

        self::assertTrue($verdict->spfFound);
        self::assertNull($verdict->spfAuthorizesProvider);
        self::assertFalse($verdict->has(IssueCode::SPF_DOES_NOT_AUTHORIZE_PROVIDER));
    }

    /** Amazon mints a selector per domain, so its absence would prove nothing. */
    public function testDkimIsNotJudgedWhereTheSelectorCannotBeKnown(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => ['v=spf1 include:amazonses.com -all']]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'ses', fromEmail: 'zamowienia@sklep.pl'));

        self::assertNull($verdict->dkimFound);
        self::assertFalse($verdict->has(IssueCode::DKIM_MISSING_FOR_PROVIDER));
    }

    public function testAMissingDkimIsReportedWhereTheSelectorIsFixed(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => ['v=spf1 include:_spf.google.com -all']]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'gmail', fromEmail: 'zamowienia@sklep.pl'));

        self::assertFalse($verdict->dkimFound);
        self::assertTrue($verdict->has(IssueCode::DKIM_MISSING_FOR_PROVIDER));
        self::assertTrue($verdict->isDeliverabilityAtRisk());
    }

    /** A DMARC that only watches is worth a note, not an alarm. */
    public function testDmarcThatOnlyWatchesIsANoteAndNotARisk(): void
    {
        $zone = new Zone(txt: [
            'sklep.pl' => ['v=spf1 include:sendgrid.net -all'],
            's1._domainkey.sklep.pl' => ['v=DKIM1; p=abc'],
            '_dmarc.sklep.pl' => ['v=DMARC1; p=none; rua=mailto:x@sklep.pl'],
        ]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'sendgrid', fromEmail: 'zamowienia@sklep.pl'));

        self::assertSame('none', $verdict->dmarcPolicy);
        self::assertTrue($verdict->has(IssueCode::DMARC_MONITOR_ONLY));
        self::assertFalse($verdict->isDeliverabilityAtRisk());
    }

    /**
     * The real case this was built against: mailboxes on Google, transactional sending
     * authorised for a Polish provider we have no preset for, and the shop configured for
     * something else entirely.
     */
    public function testAServiceWeRecogniseWithoutAPresetIsNamed(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => [
            'v=spf1 include:_spf.google.com include:_spf.emaillabs.net.pl ~all',
        ]]);

        $verdict = (new DomainInspector($zone))->inspect(Settings::make(providerId: 'brevo', fromEmail: 'zamowienia@sklep.pl'));

        self::assertSame('EmailLabs', $verdict->otherSender);
        self::assertTrue($verdict->has(IssueCode::SPF_DOES_NOT_AUTHORIZE_PROVIDER));
    }

    // ── the suggestion side ───────────────────────────────────────────────────

    public function testASubmissionServiceInDnsIsTakenAtItsWord(): void
    {
        $zone = new Zone(srv: ['_submission._tcp.sklep.pl' => [
            ['priority' => 5, 'weight' => 0, 'port' => 587, 'target' => 'smtp.hosting.pl.'],
        ]]);

        $suggestion = (new DomainInspector($zone))->suggest('sklep.pl');

        self::assertSame('smtp.hosting.pl', $suggestion->host);
        self::assertSame(587, $suggestion->port);
        self::assertSame(Encryption::TLS, $suggestion->encryption);
        self::assertSame(Suggestion::FROM_SRV, $suggestion->source);
    }

    /** Port 465 means TLS from the first byte, whichever record it came from. */
    public function testASubmissionOn465IsImplicitTls(): void
    {
        $zone = new Zone(srv: ['_submission._tcp.wp.pl' => [
            ['priority' => 0, 'weight' => 0, 'port' => 465, 'target' => 'smtp.wp.pl.'],
        ]]);

        self::assertSame(Encryption::SSL, (new DomainInspector($zone))->suggest('wp.pl')->encryption);
    }

    /** A single dot is how DNS spells "this service is not offered here". */
    public function testAnExplicitlyRefusedServiceIsNotSuggested(): void
    {
        $zone = new Zone(srv: ['_submission._tcp.sklep.pl' => [
            ['priority' => 0, 'weight' => 0, 'port' => 0, 'target' => '.'],
        ]]);

        self::assertFalse((new DomainInspector($zone))->suggest('sklep.pl')->hasAnything());
    }

    public function testTheProviderIsSuggestedFromWhatTheDomainAuthorises(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => ['v=spf1 include:spf.brevo.com ~all']]);

        $suggestion = (new DomainInspector($zone))->suggest('sklep.pl');

        self::assertSame('brevo', $suggestion->providerId);
        // The preset knows its own server better than a guess does.
        self::assertSame('smtp-relay.brevo.com', $suggestion->host);
        self::assertSame(587, $suggestion->port);
        self::assertSame(Suggestion::FROM_SPF, $suggestion->source);
    }

    public function testAServiceWithoutAPresetIsNamedRatherThanSuggested(): void
    {
        $zone = new Zone(txt: ['sklep.pl' => ['v=spf1 include:_spf.emaillabs.net.pl ~all']]);

        $suggestion = (new DomainInspector($zone))->suggest('sklep.pl');

        self::assertNull($suggestion->providerId);
        self::assertSame('EmailLabs', $suggestion->otherSender);
        self::assertTrue($suggestion->hasAnything());
    }

    public function testADomainThatSaysNothingIsSuggestedNothing(): void
    {
        self::assertFalse((new DomainInspector(new Zone()))->suggest('sklep.pl')->hasAnything());
        self::assertFalse((new DomainInspector(new Zone(unreachable: ['sklep.pl'])))->suggest('sklep.pl')->hasAnything());
    }
}
