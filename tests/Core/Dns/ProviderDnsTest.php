<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Dns;

use Calmfox\Smtp\Core\Dns\ProviderDns;
use Calmfox\Smtp\Core\Provider\ProviderCatalog;
use PHPUnit\Framework\TestCase;

final class ProviderDnsTest extends TestCase
{
    /** A table entry for a provider nobody can pick would be dead weight nobody notices. */
    public function testEveryEntryDescribesAProviderInTheCatalogue(): void
    {
        ProviderDns::assertEveryEntryIsInTheCatalogue();
        $this->expectNotToPerformAssertions();
    }

    /** Every include is a domain, because it will be compared with one from a real record. */
    public function testEveryIncludeLooksLikeADomain(): void
    {
        foreach (ProviderCatalog::ids() as $providerId) {
            foreach (ProviderDns::spfIncludes($providerId) as $include) {
                self::assertMatchesRegularExpression('/^[a-z0-9_]([a-z0-9_-]*[a-z0-9])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9])?)+$/', $include, $providerId);
                self::assertSame(mb_strtolower($include), $include, $providerId);
            }
        }
    }

    public function testAnIncludeNamesItsProvider(): void
    {
        self::assertSame('sendgrid', ProviderDns::providerForInclude('sendgrid.net'));
        self::assertSame('brevo', ProviderDns::providerForInclude('spf.brevo.com'));
        // The name Brevo used to go by is still in plenty of records.
        self::assertSame('brevo', ProviderDns::providerForInclude('spf.sendinblue.com'));
        self::assertSame('mailgun', ProviderDns::providerForInclude('_spf.eu.mailgun.org'));
        self::assertSame('gmail', ProviderDns::providerForInclude('_SPF.GOOGLE.COM'));
        self::assertNull(ProviderDns::providerForInclude('spf.nieznany.pl'));
    }

    /**
     * Resend delivers through Amazon, so `amazonses.com` cannot tell the two apart. It resolves
     * to Amazon rather than guessing, and both providers are checked against the same include.
     */
    public function testAmazonsIncludeResolvesToAmazonRatherThanToWhoeverElseUsesIt(): void
    {
        self::assertSame('ses', ProviderDns::providerForInclude('amazonses.com'));
        self::assertSame(['amazonses.com'], ProviderDns::spfIncludes('resend'));
        self::assertSame(['amazonses.com'], ProviderDns::spfIncludes('ses'));
    }

    /** Services we can name but have no preset for: enough to point somebody in the right way. */
    public function testAServiceWithoutAPresetIsStillRecognised(): void
    {
        self::assertSame('EmailLabs', ProviderDns::otherSenderForInclude('_spf.emaillabs.net.pl'));
        self::assertSame('GetResponse', ProviderDns::otherSenderForInclude('_spf.getresponse.com'));
        self::assertNull(ProviderDns::otherSenderForInclude('sendgrid.net'));
        self::assertNull(ProviderDns::providerForInclude('_spf.emaillabs.net.pl'));
    }

    /**
     * DKIM is only judged where the selector is the same for every customer. Where a provider
     * mints one per domain, the absence of a guessed name proves nothing, so there is no entry
     * and no verdict.
     */
    public function testSelectorsExistOnlyWhereTheyAreDeterministic(): void
    {
        self::assertSame(['google'], ProviderDns::dkimSelectors('gmail'));
        self::assertSame(['s1', 's2'], ProviderDns::dkimSelectors('sendgrid'));
        self::assertSame(['selector1', 'selector2'], ProviderDns::dkimSelectors('microsoft365'));

        foreach (['ses', 'resend', 'postmark', 'custom'] as $providerId) {
            self::assertSame([], ProviderDns::dkimSelectors($providerId), $providerId);
        }
    }

    public function testWeKnowWhichProvidersWeCanSayAnythingAbout(): void
    {
        self::assertTrue(ProviderDns::isKnown('brevo'));
        self::assertFalse(ProviderDns::isKnown('custom'));
    }
}
