<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\ResourceModel\LogEntry;

use Calmfox\Smtp\Model\LogEntry;
use Calmfox\Smtp\Model\ResourceModel\LogEntry as LogEntryResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(LogEntry::class, LogEntryResource::class);
    }
}
