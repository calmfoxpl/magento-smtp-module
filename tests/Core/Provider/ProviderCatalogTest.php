<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Provider;

use Calmfox\Smtp\Core\Provider\ProviderCatalog;
use Calmfox\Smtp\Core\Settings\Encryption;
use PHPUnit\Framework\TestCase;

final class ProviderCatalogTest extends TestCase
{
    public function testTheCatalogIsInternallyConsistent(): void
    {
        ProviderCatalog::assertSane();

        self::assertTrue(ProviderCatalog::has(ProviderCatalog::CUSTOM));
        self::assertGreaterThan(10, \count(ProviderCatalog::all()));
    }

    /** Every entry has to be usable: a host, a port, and something to say about the fields. */
    public function testEveryPresetIsComplete(): void
    {
        foreach (ProviderCatalog::all() as $id => $provider) {
            self::assertSame($id, $provider->id);
            self::assertNotSame('', $provider->label, $id);
            self::assertGreaterThan(0, $provider->port, $id);
            self::assertLessThanOrEqual(65535, $provider->port, $id);
            self::assertNotSame('', $provider->usernameHint, $id);
            self::assertNotSame('', $provider->passwordHint, $id);

            if ($provider->isCustom()) {
                self::assertSame('', $provider->host);
                continue;
            }
            self::assertMatchesRegularExpression('/^[a-z0-9.-]+\.[a-z]{2,}$/', $provider->host, $id);
        }
    }

    /**
     * Nobody should have to wonder whether a preset sends a password in the clear. None of them
     * do, and this is the test that keeps it that way when the next one is added.
     */
    public function testNoPresetSendsCredentialsUnencrypted(): void
    {
        foreach (ProviderCatalog::all() as $id => $provider) {
            if ($provider->isCustom()) {
                continue;
            }
            self::assertNotSame(Encryption::NONE, $provider->encryption, $id);
        }
    }

    /** A pinned login is pinned: whatever the shopkeeper types, the provider gets its word. */
    public function testPinnedLoginsAreHonouredAndOthersAreNot(): void
    {
        self::assertSame('apikey', ProviderCatalog::get('sendgrid')->username('typed@example.com', 'key'));
        self::assertSame('resend', ProviderCatalog::get('resend')->username('', 'key'));
        self::assertSame('typed@example.com', ProviderCatalog::get('brevo')->username('typed@example.com', 'key'));
    }

    public function testAnUnknownIdIsTheCustomPreset(): void
    {
        self::assertTrue(ProviderCatalog::get('no-such-provider')->isCustom());
    }

    /** Region hosts are alternatives to the default, not extras that shadow it. */
    public function testRegionalHostsIncludeTheDefaultOne(): void
    {
        foreach (ProviderCatalog::all() as $id => $provider) {
            if ([] === $provider->hostVariants) {
                continue;
            }
            self::assertContains($provider->host, $provider->hosts(), $id);
            self::assertSame($provider->host, $provider->hosts()[0], $id);
        }
    }

    /** The two mailbox providers carry a warning, because they are the two that disappoint. */
    public function testTheMailboxProvidersWarnAboutTheirLimits(): void
    {
        foreach (['gmail', 'microsoft365'] as $id) {
            self::assertNotSame('', ProviderCatalog::get($id)->caveat, $id);
        }
    }
}
