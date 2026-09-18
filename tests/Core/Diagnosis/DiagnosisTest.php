<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Diagnosis;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Diagnosis\Diagnosis;
use Calmfox\Smtp\Core\Diagnosis\Hint;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Tests\Support\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiagnosisTest extends TestCase
{
    /**
     * The real messages, as PHP and the providers actually word them. Matching on the words is
     * what makes the module portable: the error numbers differ between macOS and Linux, these
     * phrases have not changed in twenty years.
     *
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function connectionErrors(): iterable
    {
        yield 'a typo in the host name' => ['php_network_getaddresses: getaddrinfo failed: Name or service not known', 0, Cause::DNS_FAILURE];
        yield 'the wrong port' => ['Connection refused', 61, Cause::CONNECT_REFUSED];
        yield 'a port closed by the hosting company' => ['Operation timed out', 60, Cause::CONNECT_TIMEOUT];
        yield 'no route' => ['No route to host', 65, Cause::NETWORK_UNREACHABLE];
        yield 'an expired certificate' => ['stream_socket_enable_crypto(): SSL operation failed: certificate verify failed', 0, Cause::CERTIFICATE_REJECTED];
        yield 'plain text where TLS was expected' => ['SSL routines:ssl3_get_record:wrong version number', 0, Cause::TLS_HANDSHAKE_FAILED];
        yield 'a server that hung up' => ['Connection reset by peer', 54, Cause::PROTOCOL_ERROR];
    }

    #[DataProvider('connectionErrors')]
    public function testAConnectionFailureIsNamed(string $message, int $errno, string $expected): void
    {
        self::assertSame($expected, Diagnosis::fromConnectionError($errno, $message));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function sendFailures(): iterable
    {
        yield 'Google' => ['535-5.7.8 Username and Password not accepted. For more information, go to support.google.com', Cause::AUTH_REJECTED];
        yield 'SendGrid' => ['535 Authentication failed: Bad username / password', Cause::AUTH_REJECTED];
        yield 'a rate limit' => ['421 4.7.0 Too many messages, try again later', Cause::SERVER_BUSY];
        yield 'the SES sandbox' => ['554 Message rejected: Email address is not verified', Cause::MESSAGE_REJECTED];
        yield 'a relay that will not relay' => ['554 5.7.1 Relay access denied', Cause::MESSAGE_REJECTED];
        yield 'a dead connection' => ['Could not open socket: Connection refused', Cause::CONNECT_REFUSED];
    }

    #[DataProvider('sendFailures')]
    public function testAMessageThatWouldNotGoOutIsNamed(string $message, string $expected): void
    {
        self::assertSame($expected, Diagnosis::fromSendFailure($message));
    }

    /** The point of knowing the provider: one thing to check, not five. */
    public function testTheHintIsTheProvidersOwnFavouriteMistake(): void
    {
        self::assertSame(
            Hint::SES_SMTP_CREDENTIALS,
            Diagnosis::hint(Cause::AUTH_REJECTED, Settings::make(providerId: 'ses')),
        );
        self::assertSame(
            Hint::GMAIL_APP_PASSWORD,
            Diagnosis::hint(Cause::AUTH_REJECTED, Settings::make(providerId: 'gmail')),
        );
        self::assertSame(
            Hint::MICROSOFT_SMTP_AUTH,
            Diagnosis::hint(Cause::AUTH_UNSUPPORTED, Settings::make(providerId: 'microsoft365')),
        );
        self::assertSame(
            Hint::MAILGUN_REGION,
            Diagnosis::hint(Cause::AUTH_REJECTED, Settings::make(providerId: 'mailgun')),
        );
    }

    public function testATimeoutBlamesThePortBeforeAnythingElse(): void
    {
        self::assertSame(Hint::PORT_BLOCKED, Diagnosis::hint(Cause::CONNECT_TIMEOUT, Settings::make()));
    }

    public function testAMismatchedPortIsSuggestedWhenTlsCannotBeAgreed(): void
    {
        $implicitOnStartTlsPort = Settings::make(encryption: Encryption::SSL, port: 587);

        self::assertSame(
            Hint::WRONG_PORT_FOR_ENCRYPTION,
            Diagnosis::hint(Cause::TLS_HANDSHAKE_FAILED, $implicitOnStartTlsPort),
        );
    }

    public function testASandboxedAmazonAccountIsRecognisedFromARejection(): void
    {
        self::assertSame(Hint::SES_SANDBOX, Diagnosis::hint(Cause::MESSAGE_REJECTED, Settings::make(providerId: 'ses')));
    }

    public function testAProviderWithNoFamousMistakeGetsNoHint(): void
    {
        self::assertNull(Diagnosis::hint(Cause::AUTH_REJECTED, Settings::make(providerId: 'custom')));
    }

    public function testAnUnrecognisedMessageIsAdmittedRatherThanGuessedAt(): void
    {
        self::assertSame(Cause::UNKNOWN, Diagnosis::fromConnectionError(0, 'something nobody has seen before'));
        self::assertSame(Cause::UNKNOWN, Diagnosis::fromSendFailure('something nobody has seen before'));
    }
}
