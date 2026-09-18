<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Log;

use Calmfox\Smtp\Core\Log\Redaction;
use PHPUnit\Framework\TestCase;

final class RedactionTest extends TestCase
{
    public function testThePasswordDoesNotSurviveInTextWeStore(): void
    {
        $text = Redaction::apply('535 rejected for shop@example.com with an-api-key', ['an-api-key']);

        self::assertStringNotContainsString('an-api-key', $text);
        self::assertStringContainsString(Redaction::MASK, $text);
        // Everything that is not the credential is kept: that is what makes the entry useful.
        self::assertStringContainsString('shop@example.com', $text);
    }

    /** The second-hand copy: a password base64 encoded on the wire is still the password. */
    public function testAnEncodedPasswordIsCaughtToo(): void
    {
        $text = Redaction::apply('we sent ' . base64_encode('an-api-key'), ['an-api-key']);

        self::assertStringNotContainsString(base64_encode('an-api-key'), $text);
    }

    public function testEverythingAfterAnAuthVerbIsRemoved(): void
    {
        $transcript = "EHLO shop.example.com\nAUTH PLAIN AHNob3BAZXhhbXBsZS5jb20AaHVudGVyMg==\n235 ok";

        $text = Redaction::apply($transcript);

        self::assertStringNotContainsString('AHNob3BAZXhhbXBsZS5jb20', $text);
        self::assertStringContainsString('AUTH PLAIN ' . Redaction::MASK, $text);
        self::assertStringContainsString('235 ok', $text);
    }

    /** A bare base64 line is what AUTH LOGIN sends the user name and the password on. */
    public function testABareCredentialLineIsRemoved(): void
    {
        $text = Redaction::apply("334 VXNlcm5hbWU6\n" . base64_encode('shop@example.com') . "\n235 ok");

        self::assertStringNotContainsString(base64_encode('shop@example.com'), $text);
    }

    public function testApiKeyHeadersAreRemoved(): void
    {
        $text = Redaction::apply("POST /send\nAuthorization: Bearer abc.def.ghi\nX-Api-Key: 12345678\n");

        self::assertStringNotContainsString('abc.def.ghi', $text);
        self::assertStringNotContainsString('12345678', $text);
    }

    /** A short value would mask half the sentence and prove nothing. */
    public function testAVeryShortSecretIsNotUsedAsAPattern(): void
    {
        $text = Redaction::apply('the server said no', ['no']);

        self::assertSame('the server said no', $text);
    }

    public function testAFieldThatSoundsLikeACredentialIsMaskedWholesale(): void
    {
        $data = Redaction::applyToArray([
            'host' => 'mail.example.com',
            'password' => 'hunter2',
            'api_key' => 'SG.abcdef',
            'settings' => ['smtp_secret' => 'shhh', 'port' => 587],
        ]);

        self::assertSame('mail.example.com', $data['host']);
        self::assertSame(Redaction::MASK, $data['password']);
        self::assertSame(Redaction::MASK, $data['api_key']);
        self::assertSame(Redaction::MASK, $data['settings']['smtp_secret']);
        self::assertSame(587, $data['settings']['port']);
    }
}
