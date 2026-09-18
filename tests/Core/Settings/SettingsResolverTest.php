<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Settings;

use Calmfox\Smtp\Core\Settings\AuthMethod;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Core\Settings\IssueCode;
use Calmfox\Smtp\Core\Settings\SettingsResolver;
use PHPUnit\Framework\TestCase;

final class SettingsResolverTest extends TestCase
{
    private SettingsResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SettingsResolver();
    }

    public function testAPresetFillsInEverythingThatWasNotTyped(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'provider' => 'brevo',
            'username' => 'shop@example.com',
            'password' => 'an-smtp-key',
        ]);

        self::assertSame('smtp-relay.brevo.com', $resolved->settings->host);
        self::assertSame(587, $resolved->settings->port);
        self::assertSame(Encryption::TLS, $resolved->settings->encryption);
        self::assertTrue($resolved->isUsable());
        self::assertSame([], $resolved->issues);
    }

    /** A typed host beats the preset, because a preset is a starting point. */
    public function testATypedHostWins(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'provider' => 'mailgun',
            'host' => 'smtp.eu.mailgun.org',
            'username' => 'postmaster@mg.example.com',
            'password' => 'secret',
        ]);

        self::assertSame('smtp.eu.mailgun.org', $resolved->settings->host);
    }

    /** People paste what the provider's page shows them, and that is often a URL. */
    public function testAHostPastedAsAUrlWithAPortIsUnderstood(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'host' => 'smtp://Mail.Example.COM:2525/',
            'username' => 'u',
            'password' => 'p',
        ]);

        self::assertSame('mail.example.com', $resolved->settings->host);
        self::assertSame(2525, $resolved->settings->port);
    }

    public function testAnExplicitPortBeatsOneFoundInTheHost(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'host' => 'mail.example.com:2525',
            'port' => '587',
            'username' => 'u',
            'password' => 'p',
        ]);

        self::assertSame(587, $resolved->settings->port);
    }

    /** SendGrid accepts exactly one login, and it is not an e-mail address. */
    public function testAProviderThatPinsItsLoginGetsItWhateverWasTyped(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'provider' => 'sendgrid',
            'username' => 'shop@example.com',
            'password' => 'SG.a-key',
        ]);

        self::assertSame('apikey', $resolved->settings->username);
    }

    /** Postmark wants its server token in both fields; nobody reads that twice. */
    public function testAProviderThatWantsItsTokenTwiceGetsIt(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'provider' => 'postmark',
            'password' => 'a-server-token',
        ]);

        self::assertSame('a-server-token', $resolved->settings->username);
        self::assertTrue($resolved->isUsable());
    }

    /** Google prints App passwords in groups of four and ignores the spaces itself. */
    public function testTheSpacesInAGoogleAppPasswordAreForgiven(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'provider' => 'gmail',
            'username' => 'shop@example.com',
            'password' => 'abcd efgh ijkl mnop',
        ]);

        self::assertSame('abcdefghijklmnop', $resolved->settings->password);
    }

    public function testNothingIsInspectedWhileTheModuleIsSwitchedOff(): void
    {
        $resolved = $this->resolver->resolve(['enabled' => '0', 'provider' => 'custom']);

        self::assertSame([], $resolved->issues);
        self::assertFalse($resolved->isUsable());
    }

    public function testAMissingHostIsAnError(): void
    {
        $resolved = $this->resolver->resolve(['enabled' => '1', 'provider' => 'custom', 'username' => 'u', 'password' => 'p']);

        self::assertTrue($resolved->has(IssueCode::HOST_MISSING));
        self::assertFalse($resolved->isUsable());
    }

    public function testCredentialsWithNoPasswordAreAnError(): void
    {
        $resolved = $this->resolver->resolve(['enabled' => '1', 'host' => 'mail.example.com', 'username' => 'u']);

        self::assertTrue($resolved->has(IssueCode::PASSWORD_MISSING));
        self::assertFalse($resolved->isUsable());
    }

    /** 465 without encryption is not a warning, it is a connection that never answers. */
    public function testPlainTextOnTheImplicitTlsPortIsAnError(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1',
            'host' => 'mail.example.com',
            'port' => '465',
            'encryption' => '',
            'username' => 'u',
            'password' => 'p',
        ]);

        self::assertTrue($resolved->has(IssueCode::ENCRYPTION_MISSING_ON_IMPLICIT_PORT));
        self::assertFalse($resolved->isUsable());
    }

    public function testTheTwoWaysOfMixingUpPortAndEncryptionAreBothCaught(): void
    {
        $starttlsOn465 = $this->resolver->resolve([
            'enabled' => '1', 'host' => 'h', 'port' => '465', 'encryption' => Encryption::TLS,
            'username' => 'u', 'password' => 'p',
        ]);
        $implicitOn587 = $this->resolver->resolve([
            'enabled' => '1', 'host' => 'h', 'port' => '587', 'encryption' => Encryption::SSL,
            'username' => 'u', 'password' => 'p',
        ]);

        self::assertTrue($starttlsOn465->has(IssueCode::STARTTLS_ON_IMPLICIT_PORT));
        self::assertTrue($implicitOn587->has(IssueCode::IMPLICIT_TLS_ON_STARTTLS_PORT));
        // Both are only warnings: the shop can still be told to try, and sometimes it works.
        self::assertTrue($starttlsOn465->isUsable());
        self::assertTrue($implicitOn587->isUsable());
    }

    public function testAPasswordWithNoEncryptionIsWarnedAbout(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1', 'host' => 'mail.example.com', 'port' => '25',
            'encryption' => '', 'username' => 'u', 'password' => 'p',
        ]);

        self::assertTrue($resolved->has(IssueCode::CREDENTIALS_WITHOUT_ENCRYPTION));
        self::assertTrue($resolved->has(IssueCode::PORT_25));
        self::assertTrue($resolved->isUsable());
    }

    /** Magento drops every message while this is on, so nothing else matters. */
    public function testSendingSwitchedOffInMagentoIsAnError(): void
    {
        $resolved = $this->resolver->resolve(
            ['enabled' => '1', 'provider' => 'brevo', 'username' => 'u', 'password' => 'p'],
            ['disable' => '1'],
        );

        self::assertTrue($resolved->has(IssueCode::EMAIL_DISABLED_IN_MAGENTO));
        self::assertFalse($resolved->isUsable());
    }

    /** Two servers configured in one shop is not broken, but it will mislead somebody. */
    public function testASecondServerInMagentosOwnSettingsIsWarnedAbout(): void
    {
        $resolved = $this->resolver->resolve(
            ['enabled' => '1', 'provider' => 'brevo', 'username' => 'u', 'password' => 'p'],
            ['transport' => 'smtp', 'host' => 'old.example.com', 'port' => '587'],
        );

        self::assertTrue($resolved->has(IssueCode::MAGENTO_SMTP_ALSO_CONFIGURED));
        self::assertTrue($resolved->isUsable());
    }

    public function testATimeoutIsKeptWithinReason(): void
    {
        $tooLong = $this->resolver->resolve(['enabled' => '1', 'host' => 'h', 'timeout' => '600', 'username' => 'u', 'password' => 'p']);
        $nonsense = $this->resolver->resolve(['enabled' => '1', 'host' => 'h', 'timeout' => '-1', 'username' => 'u', 'password' => 'p']);

        self::assertSame(30, $tooLong->settings->timeout);
        self::assertSame(5, $nonsense->settings->timeout);
    }

    /** A preset that names a mechanism knows more than "auto" does. */
    public function testAPresetCanInsistOnAMechanism(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1', 'provider' => 'gmail', 'auth' => AuthMethod::AUTO,
            'username' => 'shop@example.com', 'password' => 'app password',
        ]);

        self::assertSame(AuthMethod::LOGIN, $resolved->settings->authMethod);
    }

    public function testAnUnknownProviderFallsBackToTheCustomPresetRatherThanBreaking(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1', 'provider' => 'a-provider-that-was-removed',
            'host' => 'mail.example.com', 'username' => 'u', 'password' => 'p',
        ]);

        self::assertSame('custom', $resolved->settings->providerId);
        self::assertTrue($resolved->isUsable());
    }

    public function testAnInvalidSenderAddressIsAWarningAndNotAStopper(): void
    {
        $resolved = $this->resolver->resolve([
            'enabled' => '1', 'host' => 'h', 'username' => 'u', 'password' => 'p',
            'from_email' => 'not an address',
        ]);

        self::assertTrue($resolved->has(IssueCode::FROM_EMAIL_INVALID));
        self::assertTrue($resolved->isUsable());
    }
}
