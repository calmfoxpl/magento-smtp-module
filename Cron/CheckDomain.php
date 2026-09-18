<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Cron;

use Calmfox\Smtp\Model\Dns\DomainCheck;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks the sender domain what it publishes, four times a day, so that the panel has an answer
 * without any page ever waiting for one.
 *
 * Separate from the connection check because it answers a different question on a different
 * clock. A refused password matters within the quarter hour; an SPF record that does not list
 * the provider has usually been wrong for weeks and will not be fixed in the next five minutes.
 *
 * It never throws. A cron group that dies takes the shop's indexers and its order e-mail with
 * it, and no DNS lookup is worth that.
 */
class CheckDomain
{
    public function __construct(
        private readonly DomainCheck $domains,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        foreach ($this->storeIds() as $storeId) {
            try {
                $this->domains->refresh($storeId);
            } catch (\Throwable $error) {
                $this->logger->warning('Calmfox SMTP: the sender domain could not be checked.', ['exception' => $error]);
            }
        }
    }

    /** @return list<int> */
    private function storeIds(): array
    {
        $ids = [0];
        try {
            foreach ($this->storeManager->getStores() as $store) {
                if ($store->getIsActive()) {
                    $ids[] = (int) $store->getId();
                }
            }
        } catch (\Throwable) {
            // The default configuration is still worth checking on its own.
        }

        return array_values(array_unique($ids));
    }
}
