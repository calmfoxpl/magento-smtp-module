<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Settings;

/**
 * The settings a message is actually sent with, after the preset, the typed values and the
 * provider's own rules have been reconciled. Immutable on purpose: the transport, the health
 * check and the test button all have to be looking at the same thing, or the panel says one
 * thing happens and something else does.
 */
final class MailSettings
{
    public const DEFAULT_TIMEOUT = 5;

    public const MIN_TIMEOUT = 1;

    public const MAX_TIMEOUT = 30;

    public function __construct(
        public readonly bool $enabled,
        public readonly string $providerId,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $authMethod,
        public readonly string $username,
        public readonly string $password,
        public readonly int $timeout = self::DEFAULT_TIMEOUT,
        public readonly ?string $fromName = null,
        public readonly ?string $fromEmail = null,
        public readonly ?string $returnPath = null,
        public readonly bool $verifyCertificate = true,
    ) {
    }

    public function endpoint(): string
    {
        return $this->host . ':' . $this->port;
    }

    public function usesAuthentication(): bool
    {
        return AuthMethod::NONE !== $this->authMethod && '' !== $this->username;
    }

    public function isEncrypted(): bool
    {
        return Encryption::NONE !== $this->encryption;
    }

    /**
     * A short, stable stamp of everything that changes the answer to "can we send?", with the
     * password hashed rather than kept.
     *
     * The health check caches its verdict, and a cached verdict about settings nobody uses any
     * more is worse than no verdict: an administrator who has just fixed the password would
     * still be looking at the old failure. Comparing stamps makes a change invalidate the
     * cache by itself, and hashing means the cache key never carries a credential.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', implode("\0", [
            $this->enabled ? '1' : '0',
            $this->providerId,
            $this->host,
            (string) $this->port,
            $this->encryption,
            $this->authMethod,
            $this->username,
            '' === $this->password ? '' : hash('sha256', $this->password),
            $this->verifyCertificate ? '1' : '0',
        ])), 0, 16);
    }

    /** The same settings with no credential in them, for a log entry or a support e-mail. */
    public function withoutSecrets(): self
    {
        return new self(
            $this->enabled,
            $this->providerId,
            $this->host,
            $this->port,
            $this->encryption,
            $this->authMethod,
            $this->username,
            '',
            $this->timeout,
            $this->fromName,
            $this->fromEmail,
            $this->returnPath,
            $this->verifyCertificate,
        );
    }
}
