<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Probe;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Probe\SmtpDialogue;
use Calmfox\Smtp\Core\Probe\Stage;
use Calmfox\Smtp\Core\Settings\AuthMethod;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Tests\Support\ScriptedStream;
use Calmfox\Smtp\Tests\Support\Settings;
use PHPUnit\Framework\TestCase;

final class SmtpDialogueTest extends TestCase
{
    private const GREETING = '220 mail.example.com ESMTP ready';

    /** @return list<string> the EHLO reply of a server that offers TLS and two mechanisms */
    private static function ehloWithStartTls(): array
    {
        return [
            '250-mail.example.com Hello shop.example.com',
            '250-SIZE 35882577',
            '250-STARTTLS',
            '250-AUTH LOGIN PLAIN',
            '250 8BITMIME',
        ];
    }

    /** @return list<string> the same server once TLS is up */
    private static function ehloAfterStartTls(): array
    {
        return [
            '250-mail.example.com Hello shop.example.com',
            '250-AUTH LOGIN PLAIN',
            '250 8BITMIME',
        ];
    }

    public function testAWorkingServerIsReportedWorking(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            ...self::ehloWithStartTls(),
            '220 ready to start TLS',
            ...self::ehloAfterStartTls(),
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 2.7.0 Authentication successful',
            '221 bye',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make(), 'shop.example.com');

        self::assertTrue($result->ok);
        self::assertSame(Stage::DONE, $result->stage);
        self::assertSame(Cause::OK, $result->cause);
        self::assertTrue($result->authenticated);
        self::assertTrue($result->encrypted);
        self::assertSame('LOGIN', $result->mechanism);
        // The capabilities reported are the ones from after the upgrade, which are the binding
        // ones: a server may hide AUTH until the connection is protected.
        self::assertTrue($result->supports('AUTH'));
        self::assertFalse($result->supports('STARTTLS'));
        self::assertTrue($stream->closed);
    }

    /** The conversation is a conversation: the right words, in the right order. */
    public function testItSaysTheRightThingsInTheRightOrder(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            ...self::ehloWithStartTls(),
            '220 ready to start TLS',
            ...self::ehloAfterStartTls(),
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 ok',
            '221 bye',
        ]);

        (new SmtpDialogue())->run($stream, Settings::make(), 'shop.example.com');

        self::assertSame([
            'EHLO shop.example.com',
            'STARTTLS',
            'EHLO shop.example.com',
            'AUTH LOGIN',
            base64_encode('shop@example.com'),
            base64_encode('correct horse'),
            'QUIT',
        ], $stream->written);
    }

    /** Nothing is sent before the connection is protected. */
    public function testTheCredentialsAreOnlySentAfterTls(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            ...self::ehloWithStartTls(),
            '220 ready to start TLS',
            ...self::ehloAfterStartTls(),
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 ok',
            '221 bye',
        ]);

        (new SmtpDialogue())->run($stream, Settings::make(), 'shop.example.com');

        $tls = array_search('STARTTLS', $stream->written, true);
        $auth = array_search('AUTH LOGIN', $stream->written, true);
        self::assertIsInt($tls);
        self::assertIsInt($auth);
        self::assertLessThan($auth, $tls);
    }

    public function testPlainIsUsedWhenItIsTheOnlyMechanismOffered(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250-STARTTLS',
            '250 AUTH PLAIN',
            '220 go ahead',
            '250-mail.example.com Hello',
            '250 AUTH PLAIN',
            '235 ok',
            '221 bye',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertTrue($result->ok);
        self::assertSame('PLAIN', $result->mechanism);
        self::assertTrue($stream->wrote('AUTH PLAIN ' . base64_encode("\0shop@example.com\0correct horse")));
    }

    /** The strongest mechanism on offer wins, and the digest is the one in RFC 2195. */
    public function testCramMd5IsPreferredAndAnsweredCorrectly(): void
    {
        $challenge = '<1896.697170952@postoffice.example.net>';
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250-STARTTLS',
            '250 AUTH CRAM-MD5 LOGIN PLAIN',
            '220 go ahead',
            '250-mail.example.com Hello',
            '250 AUTH CRAM-MD5 LOGIN PLAIN',
            '334 ' . base64_encode($challenge),
            '235 ok',
            '221 bye',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame('CRAM-MD5', $result->mechanism);
        self::assertTrue($stream->wrote(base64_encode('shop@example.com ' . hash_hmac('md5', $challenge, 'correct horse'))));
    }

    /** The failure that actually happens: a key that was revoked, or a rotated password. */
    public function testARefusedPasswordIsReportedAsARefusedPassword(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            ...self::ehloWithStartTls(),
            '220 ready to start TLS',
            ...self::ehloAfterStartTls(),
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '535 5.7.8 Username and Password not accepted',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertFalse($result->ok);
        self::assertSame(Stage::AUTH, $result->stage);
        self::assertSame(Cause::AUTH_REJECTED, $result->cause);
        self::assertSame(535, $result->replyCode);
        self::assertStringContainsString('Username and Password not accepted', $result->reply);
    }

    public function testAThrottledLoginIsNotTreatedAsAWrongPassword(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            ...self::ehloWithStartTls(),
            '220 ready to start TLS',
            ...self::ehloAfterStartTls(),
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '454 4.7.0 Temporary authentication failure',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame(Cause::AUTH_TEMPORARY, $result->cause);
        self::assertTrue(Cause::isTransient($result->cause));
    }

    public function testAServerWithNoStartTlsIsReportedBeforeAnythingIsSent(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250 AUTH LOGIN PLAIN',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame(Cause::STARTTLS_UNSUPPORTED, $result->cause);
        self::assertSame(Stage::STARTTLS, $result->stage);
        self::assertFalse($stream->wroteSomethingLike('AUTH'));
        self::assertFalse($result->encrypted);
    }

    public function testAHandshakeThatFailsIsItsOwnDiagnosis(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            ...self::ehloWithStartTls(),
            '220 ready to start TLS',
        ], encryptionSucceeds: false);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame(Stage::HANDSHAKE, $result->stage);
        self::assertSame(Cause::TLS_HANDSHAKE_FAILED, $result->cause);
        self::assertFalse($stream->wroteSomethingLike('AUTH'));
    }

    /** A wrong port usually means a web server, and it is worth saying so. */
    public function testSomethingThatIsNotAMailServerIsNamedAsSuch(): void
    {
        $stream = new ScriptedStream(['HTTP/1.1 400 Bad Request']);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame(Cause::NOT_SMTP, $result->cause);
        self::assertSame(Stage::GREETING, $result->stage);
        self::assertStringContainsString('HTTP/1.1', $result->reply);
    }

    public function testSilenceIsATimeout(): void
    {
        $result = (new SmtpDialogue())->run(new ScriptedStream([]), Settings::make());

        self::assertSame(Cause::TIMEOUT, $result->cause);
        self::assertSame(Stage::GREETING, $result->stage);
    }

    public function testABusyServerIsTransientRatherThanBroken(): void
    {
        $stream = new ScriptedStream(['421 4.7.0 Too many concurrent connections']);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame(Cause::SERVER_BUSY, $result->cause);
        self::assertTrue(Cause::isTransient($result->cause));
        self::assertSame(421, $result->replyCode);
    }

    public function testAConnectionThatDiesMidSentenceIsReportedAndClosed(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            ScriptedStream::HANGS_UP,
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame(Cause::TIMEOUT, $result->cause);
        self::assertSame(Stage::EHLO, $result->stage);
        self::assertStringContainsString('closed by the server', $result->reply);
        self::assertTrue($stream->closed);
    }

    /** On 465 the connection is already TLS, so STARTTLS would be a protocol error. */
    public function testImplicitTlsSkipsStartTls(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250 AUTH PLAIN',
            '235 ok',
            '221 bye',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make(encryption: Encryption::SSL, port: 465));

        self::assertTrue($result->ok);
        self::assertTrue($result->encrypted);
        self::assertFalse($stream->wrote('STARTTLS'));
    }

    public function testARelayThatWantsNoCredentialsIsNotOfferedAny(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250 8BITMIME',
            '221 bye',
        ]);

        $result = (new SmtpDialogue())->run(
            $stream,
            Settings::make(encryption: Encryption::NONE, authMethod: AuthMethod::NONE, username: '', password: ''),
        );

        self::assertTrue($result->ok);
        self::assertFalse($result->authenticated);
        self::assertFalse($stream->wroteSomethingLike('AUTH'));
    }

    /** Credentials configured, and nowhere to present them. */
    public function testAServerOfferingNoMechanismIsReportedRatherThanGuessedAt(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250-STARTTLS',
            '250 8BITMIME',
            '220 go ahead',
            '250-mail.example.com Hello',
            '250 8BITMIME',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertSame(Cause::AUTH_UNSUPPORTED, $result->cause);
        self::assertSame(Stage::AUTH, $result->stage);
    }

    /** Old servers write `AUTH=LOGIN`, and a shop on one of them still has to work. */
    public function testTheOlderSpellingOfTheAuthLineIsUnderstood(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250-STARTTLS',
            '250 AUTH=LOGIN',
            '220 go ahead',
            '250-mail.example.com Hello',
            '250 AUTH=LOGIN',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 ok',
            '221 bye',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make());

        self::assertTrue($result->ok);
        self::assertSame('LOGIN', $result->mechanism);
    }

    public function testAServerThatOnlyKnowsHeloIsStillTalkedTo(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '500 5.5.1 Command unrecognized: EHLO',
            '250 mail.example.com Hello',
            '221 bye',
        ]);

        $result = (new SmtpDialogue())->run(
            $stream,
            Settings::make(encryption: Encryption::NONE, authMethod: AuthMethod::NONE, username: '', password: ''),
        );

        self::assertTrue($result->ok);
        self::assertTrue($stream->wroteSomethingLike('HELO '));
    }

    /**
     * A mechanism chosen by hand is tried even when the server does not advertise it, because
     * servers under-advertise; but the rejection is then reported as a rejection.
     */
    public function testAChosenMechanismIsTriedEvenIfUnadvertised(): void
    {
        $stream = new ScriptedStream([
            self::GREETING,
            '250-mail.example.com Hello',
            '250-STARTTLS',
            '250 8BITMIME',
            '220 go ahead',
            '250-mail.example.com Hello',
            '250 8BITMIME',
            '504 5.5.4 Unrecognized authentication type',
        ]);

        $result = (new SmtpDialogue())->run($stream, Settings::make(authMethod: AuthMethod::PLAIN));

        self::assertTrue($stream->wroteSomethingLike('AUTH PLAIN'));
        self::assertSame(Cause::AUTH_UNSUPPORTED, $result->cause);
    }

    /** Whatever happens, the socket is not left open. */
    public function testTheConnectionIsAlwaysClosed(): void
    {
        foreach ([[], ['HTTP/1.1 500'], [self::GREETING, '500 no'], [self::GREETING, ScriptedStream::HANGS_UP]] as $script) {
            $stream = new ScriptedStream($script);
            (new SmtpDialogue())->run($stream, Settings::make());
            self::assertTrue($stream->closed);
        }
    }
}
