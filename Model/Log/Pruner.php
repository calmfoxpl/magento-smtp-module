<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Log;

use Calmfox\Smtp\Core\Log\Retention;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\ResourceModel\LogEntry as LogEntryResource;
use Psr\Log\LoggerInterface;

/**
 * Throws away log entries that are past their retention.
 *
 * This runs whether or not the log is switched on, because the reason to delete is not
 * housekeeping: a shop that turned the log off last month should not still be holding a
 * fortnight of its customers' order confirmations.
 *
 * The retention of the shortest-lived store view wins. Anything else would keep data longer
 * than one of the shop's own settings says to.
 */
class Pruner
{
    public function __construct(
        private readonly Config $config,
        private readonly LogEntryResource $resource,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prune(?int $days = null): int
    {
        $days ??= $this->config->retentionDays();
        // Zero means "all of it", which is what somebody asking to empty the log means. It is
        // spelled out here because the retention rules read a zero as "unset" and hand back the
        // default, and emptying a log with a button that quietly keeps a month would be a lie.
        $cutoff = 0 === $days ? time() + 1 : Retention::cutoff(time(), $days);

        try {
            return $this->resource->prune($cutoff);
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the log could not be pruned.', ['exception' => $error]);

            return 0;
        }
    }
}
