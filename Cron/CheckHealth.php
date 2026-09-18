<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Cron;

use Calmfox\Smtp\Model\Health\Monitor;
use Psr\Log\LoggerInterface;

/**
 * The periodic check, which is the part that makes this module worth installing.
 *
 * Without it the shop finds out that its API key expired when a customer writes to ask where
 * their confirmation is. With it, the finding out happens within a quarter of an hour, in the
 * panel, before the phone rings.
 *
 * It never throws. A cron group that dies takes the shop's indexers and its order e-mails with
 * it, and no health check is worth that.
 */
class CheckHealth
{
    public function __construct(
        private readonly Monitor $monitor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        try {
            $this->monitor->checkAll();
        } catch (\Throwable $error) {
            $this->logger->error('Calmfox SMTP: the health check could not be run.', ['exception' => $error]);
        }
    }
}
