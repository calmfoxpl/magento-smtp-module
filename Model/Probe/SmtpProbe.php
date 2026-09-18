<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Probe;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Diagnosis\Diagnosis;
use Calmfox\Smtp\Core\Probe\ProbeResult;
use Calmfox\Smtp\Core\Probe\SmtpDialogue;
use Calmfox\Smtp\Core\Probe\Stage;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Magento\Store\Model\StoreManagerInterface;

/**
 * One check of the connection: open a socket, hold the conversation, time it, name what happened.
 *
 * The socket lives here and the decisions live in the core, which is why the interesting cases
 * are unit tested and this class stays thin enough to read in one go.
 */
class SmtpProbe
{
    public function __construct(
        private readonly SmtpDialogue $dialogue,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function run(MailSettings $settings, ?int $storeId = null): ProbeResult
    {
        if ('' === $settings->host) {
            return ProbeResult::failed(Stage::CONNECT, Cause::NOT_CONFIGURED);
        }

        $started = microtime(true);
        $errno = 0;
        $error = '';

        $handle = @stream_socket_client(
            sprintf('%s%s:%d', Encryption::SSL === $settings->encryption ? 'ssl://' : 'tcp://', $settings->host, $settings->port),
            $errno,
            $error,
            (float) $settings->timeout,
            \STREAM_CLIENT_CONNECT,
            stream_context_create($this->contextOptions($settings)),
        );

        if (!\is_resource($handle)) {
            return ProbeResult::failed(
                Stage::CONNECT,
                Diagnosis::fromConnectionError($errno, $error),
                null,
                '' !== $error ? $error : 'no answer',
                milliseconds: self::since($started),
            );
        }

        $stream = new SocketStream($handle, $settings->timeout);
        $result = $this->dialogue->run($stream, $settings, $this->clientName($storeId));

        if (Cause::TLS_HANDSHAKE_FAILED === $result->cause && '' !== $stream->lastError()) {
            // PHP's warning knows whether this was a certificate or a protocol; the conversation
            // could only see that TLS did not happen.
            $result = $result->withCause(
                Diagnosis::fromConnectionError(0, $stream->lastError()),
                $stream->lastError(),
            );
        }

        return $result->withTiming(self::since($started));
    }

    /** @return array<string, array<string, mixed>> */
    private function contextOptions(MailSettings $settings): array
    {
        return [
            'ssl' => [
                'verify_peer' => $settings->verifyCertificate,
                'verify_peer_name' => $settings->verifyCertificate,
                'allow_self_signed' => !$settings->verifyCertificate,
                'peer_name' => $settings->host,
                'SNI_enabled' => true,
            ],
        ];
    }

    /**
     * The name we introduce ourselves with.
     *
     * Some providers check that it is a name and not an address, and a few reject a bare
     * `localhost`. The shop's own domain is both true and acceptable everywhere.
     */
    private function clientName(?int $storeId): string
    {
        try {
            $host = parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl(), \PHP_URL_HOST);
        } catch (\Throwable) {
            $host = null;
        }

        return \is_string($host) && '' !== $host ? $host : 'localhost';
    }

    private static function since(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
