<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Health;

use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Where the verdict is kept: one row per store view, overwritten in place.
 *
 * Every read and every write is wrapped, and that is not defensive habit — it is the module's
 * most important safety property. This class is called from the mail transport, so a missing
 * table on a shop that has not run `setup:upgrade` yet, or a database that is briefly read-only,
 * must not turn into an order confirmation that never goes out. Losing the health record is a
 * nuisance; losing the e-mail is the thing we exist to prevent.
 */
class HealthStore
{
    public const TABLE = 'calmfox_smtp_health';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function load(int $storeId): HealthState
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('store_id = ?', $storeId)
                ->limit(1);
            $row = $connection->fetchRow($select);
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the health record could not be read.', ['exception' => $error]);

            return new HealthState();
        }

        return \is_array($row) && [] !== $row ? HealthState::fromArray($row) : new HealthState();
    }

    public function save(int $storeId, HealthState $state): void
    {
        try {
            $connection = $this->resource->getConnection();
            $connection->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE),
                ['store_id' => $storeId] + $state->toArray(),
                array_keys($state->toArray()),
            );
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the health record could not be written.', ['exception' => $error]);
        }
    }

    /** @return array<int, HealthState> every store view that has ever been checked */
    public function all(): array
    {
        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll($connection->select()->from($this->resource->getTableName(self::TABLE)));
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the health records could not be read.', ['exception' => $error]);

            return [];
        }

        $states = [];
        foreach ($rows as $row) {
            $states[(int) $row['store_id']] = HealthState::fromArray($row);
        }

        return $states;
    }

    /**
     * The store view in the worst state, which is what a panel-wide warning has to be about.
     *
     * A shop with four storefronts and one broken sender is a broken shop, and the banner should
     * say so rather than averaging the four into something reassuring.
     *
     * @return array{0: int, 1: HealthState}|null
     */
    public function worst(): ?array
    {
        $rank = [Status::FAIL => 3, Status::WARN => 2, Status::UNKNOWN => 1, Status::OK => 0];
        $worst = null;

        foreach ($this->all() as $storeId => $state) {
            if (null === $worst || $rank[$state->status] > $rank[$worst[1]->status]) {
                $worst = [$storeId, $state];
            }
        }

        return $worst;
    }
}
