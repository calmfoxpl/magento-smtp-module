<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Dns;

use Calmfox\Smtp\Core\Dns\DomainInspector;
use Calmfox\Smtp\Core\Dns\DomainVerdict;
use Calmfox\Smtp\Core\Dns\SenderDomain;
use Calmfox\Smtp\Core\Dns\Suggestion;
use Calmfox\Smtp\Model\Config;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The domain check as the rest of the module sees it, with the one rule that matters: only the
 * methods named `refresh` are allowed to touch the network.
 *
 * Everything that renders — the report, the warning in the panel, the settings screen — asks for
 * the stored verdict and gets `null` when there is none. A page that waited on a nameserver to
 * tell somebody their SPF is wrong would be a worse fault than the one being reported.
 *
 * The stored verdict is keyed by the settings it was reached under, so editing the provider
 * throws it away instead of showing a verdict about a provider the shop no longer uses.
 */
class DomainCheck
{
    /** Six hours: DNS changes are made by hand, and rarely twice in a day. */
    public const LIFETIME = 21600;

    private const CACHE_TAG = 'CALMFOX_SMTP_DNS';

    public function __construct(
        private readonly Config $config,
        private readonly DomainInspector $inspector,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The stored verdict, or null. Never asks DNS anything. */
    public function stored(?int $storeId = null): ?DomainVerdict
    {
        if (!$this->config->dnsCheckEnabled($storeId)) {
            return null;
        }

        try {
            $cached = $this->cache->load($this->key($storeId));
            if (!\is_string($cached) || '' === $cached) {
                return null;
            }

            $row = $this->json->unserialize($cached);

            return \is_array($row) ? DomainVerdict::fromArray($row) : null;
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the stored domain verdict could not be read.', ['exception' => $error]);

            return null;
        }
    }

    /** Asks DNS and keeps the answer. For the button, the cron job and the console. */
    public function refresh(?int $storeId = null): ?DomainVerdict
    {
        if (!$this->config->dnsCheckEnabled($storeId)) {
            return null;
        }

        $verdict = $this->inspector->inspect($this->config->resolve($storeId)->settings);

        try {
            $this->cache->save(
                $this->json->serialize($verdict->toArray()),
                $this->key($storeId),
                [self::CACHE_TAG],
                self::LIFETIME,
            );
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the domain verdict could not be stored.', ['exception' => $error]);
        }

        return $verdict;
    }

    /**
     * What the shop's own domain suggests it is set up to send through.
     *
     * Only for the settings screen, and only on a button: this is the one place where somebody
     * is sitting in front of the panel waiting for an answer, so a wait is honest there.
     */
    public function suggest(?int $storeId = null): Suggestion
    {
        $settings = $this->config->resolve($storeId)->settings;
        $domain = SenderDomain::of($settings) ?? $this->config->senderDomainFallback($storeId);

        return null === $domain ? Suggestion::none() : $this->inspector->suggest($domain);
    }

    private function key(?int $storeId): string
    {
        $settings = $this->config->resolve($storeId)->settings;

        return sprintf('calmfox_smtp_dns_%d_%s', $storeId ?? 0, $settings->fingerprint());
    }
}
