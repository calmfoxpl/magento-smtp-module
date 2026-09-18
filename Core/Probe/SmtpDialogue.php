<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Probe;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Settings\AuthMethod;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Core\Settings\MailSettings;

/**
 * The health check itself: greeting, EHLO, STARTTLS, log in, goodbye — and not one byte more.
 *
 * It stops before `MAIL FROM`, which is the whole point. Everything up to and including the
 * password is free: it costs the provider nothing, counts against no sending quota, lands in
 * nobody's inbox, and catches the failures that actually happen — an expired API key, a
 * password rotated by a colleague, a port the hosting company closed, a certificate nobody
 * renewed, a provider that stopped offering the mechanism we use. What it cannot tell you is
 * whether a message would be delivered, and the panel says so in as many words rather than
 * letting a green light imply it.
 *
 * Every decision lives here, over a Stream, so the whole thing is tested against written-down
 * transcripts: a server that answers in French, one that refuses the password, one that offers
 * no STARTTLS, one that stops talking in the middle.
 */
final class SmtpDialogue
{
    /** Codes that mean "not now": worth retrying, not worth waking anybody. */
    private const BUSY = [421, 450, 451];

    /** Codes a server uses to say it has never heard of the command we just sent. */
    private const UNKNOWN_COMMAND = [500, 502, 504];

    public function run(Stream $stream, MailSettings $settings, string $clientName = 'localhost'): ProbeResult
    {
        $encrypted = Encryption::SSL === $settings->encryption;
        $capabilities = [];
        $stage = Stage::GREETING;

        try {
            $greeting = $this->read($stream);
            if ($greeting->isSilence()) {
                return ProbeResult::failed(Stage::GREETING, Cause::TIMEOUT, encrypted: $encrypted);
            }
            if (!$greeting->isSmtp()) {
                // Something is listening and it is not a mail server: a web server, a proxy,
                // a captive firewall. The port is the first thing to look at.
                return ProbeResult::failed(Stage::GREETING, Cause::NOT_SMTP, null, $greeting->summary(), encrypted: $encrypted);
            }
            if ($greeting->is(...self::BUSY)) {
                return ProbeResult::failed(Stage::GREETING, Cause::SERVER_BUSY, $greeting->code, $greeting->summary(), encrypted: $encrypted);
            }
            if (!$greeting->is(220)) {
                return ProbeResult::failed(Stage::GREETING, Cause::GREETING_REJECTED, $greeting->code, $greeting->summary(), encrypted: $encrypted);
            }

            $stage = Stage::EHLO;
            $hello = $this->hello($stream, $clientName);
            if (!$hello->is(250)) {
                return ProbeResult::failed(
                    Stage::EHLO,
                    $hello->is(...self::BUSY) ? Cause::SERVER_BUSY : ($hello->isSmtp() ? Cause::EHLO_REJECTED : Cause::PROTOCOL_ERROR),
                    $hello->code,
                    $hello->summary(),
                    encrypted: $encrypted,
                );
            }
            $capabilities = self::capabilities($hello);
            $mechanisms = self::mechanisms($hello);

            if (Encryption::TLS === $settings->encryption) {
                $stage = Stage::STARTTLS;
                if (!\in_array('STARTTLS', $capabilities, true)) {
                    return ProbeResult::failed(Stage::STARTTLS, Cause::STARTTLS_UNSUPPORTED, $hello->code, $hello->summary(), $capabilities);
                }

                $stream->write('STARTTLS');
                $agreed = $this->read($stream);
                if (!$agreed->is(220)) {
                    return ProbeResult::failed(Stage::STARTTLS, Cause::STARTTLS_REJECTED, $agreed->code, $agreed->summary(), $capabilities);
                }

                $stage = Stage::HANDSHAKE;
                if (!$stream->enableEncryption()) {
                    // The server agreed and then could not agree on how: an old TLS version, or
                    // a certificate we will not trust. Which of the two is in the message.
                    return ProbeResult::failed(Stage::HANDSHAKE, Cause::TLS_HANDSHAKE_FAILED, null, '', $capabilities);
                }
                $encrypted = true;

                // The capability list before TLS is not binding: servers hide AUTH until the
                // connection is protected, which is exactly the right thing for them to do.
                $stage = Stage::EHLO;
                $hello = $this->hello($stream, $clientName);
                if (!$hello->is(250)) {
                    return ProbeResult::failed(Stage::EHLO, Cause::EHLO_REJECTED, $hello->code, $hello->summary(), $capabilities, encrypted: true);
                }
                $capabilities = self::capabilities($hello);
                $mechanisms = self::mechanisms($hello);
            }

            $mechanism = null;
            $authenticated = false;

            if ($settings->usesAuthentication()) {
                $stage = Stage::AUTH;
                $mechanism = self::chooseMechanism($settings->authMethod, $mechanisms);
                if (null === $mechanism) {
                    return ProbeResult::failed(
                        Stage::AUTH,
                        Cause::AUTH_UNSUPPORTED,
                        null,
                        implode(' ', $mechanisms),
                        $capabilities,
                        encrypted: $encrypted,
                    );
                }

                $answer = $this->authenticate($stream, $mechanism, $settings->username, $settings->password);
                if (!$answer->is(235)) {
                    return ProbeResult::failed(
                        Stage::AUTH,
                        self::authCause($answer),
                        $answer->code,
                        $answer->summary(),
                        $capabilities,
                        encrypted: $encrypted,
                    );
                }
                $authenticated = true;
            }

            $this->farewell($stream);

            return ProbeResult::succeeded($capabilities, $mechanism, 0, $authenticated, $encrypted);
        } catch (StreamFailure $failure) {
            // The connection died while we were using it. From where the shopkeeper sits this
            // is the same problem as a server that stops answering, so it is reported as such,
            // with the underlying words kept for whoever has to fix it.
            return ProbeResult::failed($stage, Cause::TIMEOUT, null, $failure->getMessage(), $capabilities, encrypted: $encrypted);
        } finally {
            $stream->close();
        }
    }

    /** EHLO, falling back to HELO for the handful of servers that still only know that. */
    private function hello(Stream $stream, string $clientName): Reply
    {
        $stream->write('EHLO ' . $clientName);
        $reply = $this->read($stream);

        if ($reply->is(...self::UNKNOWN_COMMAND)) {
            $stream->write('HELO ' . $clientName);
            $reply = $this->read($stream);
        }

        return $reply;
    }

    private function authenticate(Stream $stream, string $mechanism, string $username, string $password): Reply
    {
        if (AuthMethod::keyword(AuthMethod::PLAIN) === $mechanism) {
            $stream->write('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password));

            return $this->read($stream);
        }

        if (AuthMethod::keyword(AuthMethod::LOGIN) === $mechanism) {
            $stream->write('AUTH LOGIN');
            $prompt = $this->read($stream);
            if (!$prompt->is(334)) {
                return $prompt;
            }
            $stream->write(base64_encode($username));
            $prompt = $this->read($stream);
            if (!$prompt->is(334)) {
                return $prompt;
            }
            $stream->write(base64_encode($password));

            return $this->read($stream);
        }

        $stream->write('AUTH CRAM-MD5');
        $challenge = $this->read($stream);
        if (!$challenge->is(334)) {
            return $challenge;
        }
        $decoded = base64_decode(trim($challenge->firstLine()), true);
        if (!\is_string($decoded) || '' === $decoded) {
            return Reply::of(500, ['the challenge could not be read'], '500 the challenge could not be read');
        }
        $stream->write(base64_encode($username . ' ' . hash_hmac('md5', $decoded, $password)));

        return $this->read($stream);
    }

    /** QUIT is a courtesy: a server that drops us instead of answering changes nothing. */
    private function farewell(Stream $stream): void
    {
        try {
            $stream->write('QUIT');
            $stream->readLine();
        } catch (StreamFailure) {
            // nothing left to say
        }
    }

    /**
     * One reply, however many lines it arrived on.
     *
     * A multi-line reply repeats its code with a hyphen on every line but the last, and the
     * lines that matter — the capability list — are all in the middle.
     */
    private function read(Stream $stream): Reply
    {
        $first = $stream->readLine();
        if ('' === trim($first)) {
            return Reply::silence();
        }

        $raw = [$first];
        $lines = [];
        $code = null;
        $line = $first;
        $guard = 0;

        while (true) {
            if (preg_match('/^(?<code>\d{3})(?<separator>[ \-])?(?<text>.*)$/s', $line, $match)) {
                $code ??= (int) $match['code'];
                $lines[] = rtrim($match['text']);
                $continues = '-' === ($match['separator'] ?? '');
            } else {
                // Not an SMTP line. Keep it, because its content is the diagnosis.
                $lines[] = rtrim($line);
                $continues = false;
            }

            if (!$continues || ++$guard > 64) {
                break;
            }

            $line = $stream->readLine();
            if ('' === trim($line)) {
                break;
            }
            $raw[] = $line;
        }

        return Reply::of($code, $lines, implode("\n", $raw));
    }

    /** @return list<string> the capability names from an EHLO reply, upper case */
    private static function capabilities(Reply $reply): array
    {
        $capabilities = [];
        // The first line is the server greeting itself ("mail.example.com at your service").
        foreach (\array_slice($reply->lines, 1) as $line) {
            $name = mb_strtoupper(strtok(trim($line), " \t") ?: '');
            $name = explode('=', $name)[0];
            if ('' !== $name) {
                $capabilities[] = $name;
            }
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * @return list<string> the mechanisms offered, upper case
     *
     * Both spellings are in the wild: `AUTH LOGIN PLAIN` from anything current and `AUTH=LOGIN`
     * from servers old enough to predate the standard's final wording.
     */
    private static function mechanisms(Reply $reply): array
    {
        $mechanisms = [];
        foreach (\array_slice($reply->lines, 1) as $line) {
            $line = trim($line);
            if (!preg_match('/^AUTH[ =]+(?<list>.+)$/i', $line, $match)) {
                continue;
            }
            foreach (preg_split('/[\s=]+/', trim($match['list'])) ?: [] as $mechanism) {
                if ('' !== $mechanism) {
                    $mechanisms[] = mb_strtoupper($mechanism);
                }
            }
        }

        return array_values(array_unique($mechanisms));
    }

    /**
     * @param list<string> $offered
     *
     * A configured mechanism is tried even when it is not advertised — servers under-advertise
     * as often as they over-advertise — but "auto" only picks from what was offered, so that a
     * shop keeps working when a provider retires one.
     */
    private static function chooseMechanism(string $configured, array $offered): ?string
    {
        if (AuthMethod::AUTO !== $configured) {
            $keyword = AuthMethod::keyword($configured);

            return '' === $keyword ? null : $keyword;
        }

        foreach (AuthMethod::PREFERENCE as $method) {
            if (\in_array(AuthMethod::keyword($method), $offered, true)) {
                return AuthMethod::keyword($method);
            }
        }

        return null;
    }

    private static function authCause(Reply $reply): string
    {
        if ($reply->is(454)) {
            return Cause::AUTH_TEMPORARY;
        }
        if ($reply->is(...self::BUSY)) {
            return Cause::SERVER_BUSY;
        }
        if ($reply->is(...self::UNKNOWN_COMMAND)) {
            return Cause::AUTH_UNSUPPORTED;
        }
        if ($reply->is(530, 534, 535, 538)) {
            return Cause::AUTH_REJECTED;
        }

        return $reply->isSmtp() ? Cause::AUTH_REJECTED : Cause::PROTOCOL_ERROR;
    }
}
