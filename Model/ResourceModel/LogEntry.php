<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class LogEntry extends AbstractDb
{
    public const TABLE = 'calmfox_smtp_log';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'entity_id');
    }

    /**
     * Everything older than the cutoff, in bounded batches.
     *
     * A shop that has been sending for a year before anyone turns retention on would otherwise
     * meet a single delete of millions of rows, which locks the table for as long as it takes.
     * Deleting in slices keeps a cron job from becoming an outage.
     */
    public function prune(int $cutoff, int $batch = 5000): int
    {
        $connection = $this->getConnection();
        $table = $this->getMainTable();
        $removed = 0;

        do {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, 'entity_id')
                    ->where('created_at < ?', date('Y-m-d H:i:s', $cutoff))
                    ->limit($batch),
            );
            if ([] === $ids) {
                break;
            }
            $removed += $connection->delete($table, ['entity_id IN (?)' => $ids]);
        } while (\count($ids) === $batch);

        return $removed;
    }

    /**
     * The stored message of an entry that is being sent again, dropped once it has gone out.
     *
     * Keeping the body of a message that was successfully sent serves nobody: the resend is
     * done, and what is left is personal data with no purpose.
     */
    public function forgetBody(int $entityId): void
    {
        $this->getConnection()->update($this->getMainTable(), ['raw_message' => null], ['entity_id = ?' => $entityId]);
    }
}
