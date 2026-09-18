<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model;

use Calmfox\Smtp\Core\Log\Outcome;
use Magento\Framework\Model\AbstractModel;

/**
 * One message the shop tried to send.
 *
 * @method string getCreatedAt()
 * @method int getStoreId()
 * @method string getOutcome()
 * @method string getProvider()
 * @method string getEndpoint()
 * @method string getFromAddress()
 * @method string getRecipients()
 * @method string getSubject()
 * @method int getMessageSize()
 * @method int getDurationMs()
 * @method int getAttempts()
 * @method string|null getCause()
 * @method string|null getError()
 * @method string|null getRawMessage()
 * @method int|null getResentAt()
 */
class LogEntry extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\LogEntry::class);
    }

    public function failed(): bool
    {
        return Outcome::FAILED === $this->getOutcome();
    }

    /** A resend is only offered where there is something left to send. */
    public function canBeResent(): bool
    {
        return $this->failed() && '' !== (string) $this->getRawMessage();
    }
}
