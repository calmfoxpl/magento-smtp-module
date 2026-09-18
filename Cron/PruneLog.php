<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Cron;

use Calmfox\Smtp\Model\Log\Pruner;
use Psr\Log\LoggerInterface;

/** Keeps the log to its retention. Nightly, and quiet about it unless something is deleted. */
class PruneLog
{
    public function __construct(
        private readonly Pruner $pruner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        try {
            $removed = $this->pruner->prune();
            if ($removed > 0) {
                $this->logger->info(sprintf('Calmfox SMTP: %d log entries past their retention were deleted.', $removed));
            }
        } catch (\Throwable $error) {
            $this->logger->error('Calmfox SMTP: the log could not be pruned.', ['exception' => $error]);
        }
    }
}
