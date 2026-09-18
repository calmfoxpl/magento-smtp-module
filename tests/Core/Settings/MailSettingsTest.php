<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Settings;

use Calmfox\Smtp\Core\Settings\AuthMethod;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Tests\Support\Settings;
use PHPUnit\Framework\TestCase;

final class MailSettingsTest extends TestCase
{
    public function testTheFingerprintChangesWithAnythingThatChangesTheAnswer(): void
    {
        $base = Settings::make();

        self::assertSame($base->fingerprint(), Settings::make()->fingerprint());
        self::assertNotSame($base->fingerprint(), Settings::make(host: 'other.example.com')->fingerprint());
        self::assertNotSame($base->fingerprint(), Settings::make(port: 465)->fingerprint());
        self::assertNotSame($base->fingerprint(), Settings::make(password: 'a new key')->fingerprint());
        self::assertNotSame($base->fingerprint(), Settings::make(encryption: Encryption::SSL)->fingerprint());
    }

    /** A timeout is not a credential and not a server: changing it must not wipe the history. */
    public function testTheFingerprintIgnoresWhatDoesNotChangeTheAnswer(): void
    {
        $base = Settings::make();
        $slower = new \Calmfox\Smtp\Core\Settings\MailSettings(
            enabled: true,
            providerId: $base->providerId,
            host: $base->host,
            port: $base->port,
            encryption: $base->encryption,
            authMethod: $base->authMethod,
            username: $base->username,
            password: $base->password,
            timeout: 20,
            fromName: 'A different shop name',
        );

        self::assertSame($base->fingerprint(), $slower->fingerprint());
    }

    /** The fingerprint is stored and travels in cache keys, so it cannot be the password. */
    public function testTheFingerprintDoesNotContainTheCredential(): void
    {
        $fingerprint = Settings::make(password: 'an-api-key')->fingerprint();

        self::assertStringNotContainsString('an-api-key', $fingerprint);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $fingerprint);
    }

    public function testACopyForSupportHasNoPasswordInIt(): void
    {
        $safe = Settings::make(password: 'an-api-key')->withoutSecrets();

        self::assertSame('', $safe->password);
        self::assertSame('shop@example.com', $safe->username);
    }

    public function testAuthenticationIsOnlyUsedWhenThereIsSomethingToSend(): void
    {
        self::assertTrue(Settings::make()->usesAuthentication());
        self::assertFalse(Settings::make(authMethod: AuthMethod::NONE)->usesAuthentication());
        self::assertFalse(Settings::make(username: '')->usesAuthentication());
    }

    public function testTheEndpointReadsTheWayPeopleWriteIt(): void
    {
        self::assertSame('mail.example.com:587', Settings::make()->endpoint());
    }
}
