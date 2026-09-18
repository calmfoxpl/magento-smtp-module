<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Probe;

use Calmfox\Smtp\Core\Probe\Stream;
use Calmfox\Smtp\Core\Probe\StreamFailure;

/**
 * A socket, behind the one interface the conversation needs.
 *
 * Two details here are the difference between a health check and a hang. The read timeout is set
 * on the stream itself, because a mail server that accepts a connection and then says nothing is
 * a real failure mode and `fgets` on such a socket waits for ever — which, on a cron run, means
 * a check that never finishes. And the warning PHP emits when TLS cannot be agreed is captured
 * rather than left to the log, because that sentence names the difference between an expired
 * certificate and a server that does not speak TLS on this port.
 */
class SocketStream implements Stream
{
    /** @var resource|null */
    private $handle;

    private string $lastError = '';

    /** @param resource $handle */
    public function __construct($handle, int $timeout)
    {
        $this->handle = $handle;
        stream_set_timeout($handle, $timeout);
        stream_set_blocking($handle, true);
    }

    public function write(string $line): void
    {
        if (!\is_resource($this->handle)) {
            throw new StreamFailure('the connection is closed');
        }
        if (false === @fwrite($this->handle, $line . "\r\n")) {
            throw new StreamFailure('the connection could not be written to');
        }
    }

    public function readLine(): string
    {
        if (!\is_resource($this->handle)) {
            throw new StreamFailure('the connection is closed');
        }

        $line = @fgets($this->handle, 1024);
        $meta = stream_get_meta_data($this->handle);

        if (true === ($meta['timed_out'] ?? false)) {
            // Silence, which the conversation reads as a timeout. Not an exception: a server
            // that stops talking is a finding, not an accident.
            return '';
        }
        if (!\is_string($line)) {
            return '';
        }

        return rtrim($line, "\r\n");
    }

    public function enableEncryption(): bool
    {
        if (!\is_resource($this->handle)) {
            return false;
        }

        $this->lastError = '';
        set_error_handler(function (int $number, string $message): bool {
            $this->lastError = $message;

            return true;
        });

        try {
            $raised = stream_socket_enable_crypto(
                $this->handle,
                true,
                \STREAM_CRYPTO_METHOD_TLS_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            );
        } finally {
            restore_error_handler();
        }

        return true === $raised;
    }

    public function close(): void
    {
        if (\is_resource($this->handle)) {
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    /** Whatever PHP said about the handshake, for whoever has to act on it. */
    public function lastError(): string
    {
        return $this->lastError;
    }
}
