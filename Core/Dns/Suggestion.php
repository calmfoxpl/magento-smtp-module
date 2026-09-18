<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

use Calmfox\Smtp\Core\Provider\ProviderCatalog;
use Calmfox\Smtp\Core\Settings\Encryption;

/**
 * What the domain's own records suggest, and where each part of it came from.
 *
 * The source travels with the suggestion because it decides how firmly the panel may put it. A
 * submission service published in DNS is the domain's own answer about its own mail, so it can
 * be offered as one; a provider inferred from an SPF include is a guess about intent — the shop
 * may authorise three services and mean a fourth — so it is offered as a question.
 *
 * Nothing here ever carries a credential. DNS can say which service a domain uses; it cannot say
 * who the shop is at that service, and a suggestion that filled in a login would be inventing
 * one.
 */
final class Suggestion
{
    public const FROM_SRV = 'srv';

    public const FROM_SPF = 'spf';

    private function __construct(
        public readonly ?string $providerId = null,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $encryption = null,
        public readonly ?string $source = null,
        /** A service the domain authorises that has no preset: named, and left to be typed. */
        public readonly ?string $otherSender = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function fromSrv(string $host, int $port, bool $implicitTls): self
    {
        return new self(
            host: $host,
            port: $port,
            encryption: $implicitTls ? Encryption::SSL : Encryption::TLS,
            source: self::FROM_SRV,
        );
    }

    public function withProvider(string $providerId, string $source): self
    {
        $provider = ProviderCatalog::get($providerId);

        return new self(
            providerId: $providerId,
            // A preset knows its own server better than a domain's SRV record does, so the
            // provider's host wins where we have one; the SRV answer stays for the custom case.
            host: $provider->isCustom() ? $this->host : $provider->host,
            port: $provider->isCustom() ? $this->port : $provider->port,
            encryption: $provider->isCustom() ? $this->encryption : $provider->encryption,
            source: $source,
            otherSender: $this->otherSender,
        );
    }

    public function withOtherSender(string $name): self
    {
        return new self(
            host: $this->host,
            port: $this->port,
            encryption: $this->encryption,
            source: $this->source,
            otherSender: $name,
        );
    }

    public function hasAnything(): bool
    {
        return null !== $this->providerId || null !== $this->host || null !== $this->otherSender;
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerId,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'source' => $this->source,
            'other_sender' => $this->otherSender,
        ];
    }
}
