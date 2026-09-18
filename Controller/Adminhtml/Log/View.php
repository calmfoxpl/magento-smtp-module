<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Log;

use Calmfox\Smtp\Model\LogEntry;
use Calmfox\Smtp\Model\LogEntryFactory;
use Calmfox\Smtp\Model\ResourceModel\LogEntry as LogEntryResource;
use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/** One entry in full: what was sent, where it went, and what the server said about it. */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::log';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $pages,
        private readonly LogEntryFactory $entries,
        private readonly LogEntryResource $resource,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $entry = $this->load((int) $this->getRequest()->getParam('entity_id'));
        if (null === $entry) {
            $this->messageManager->addErrorMessage((string) __('That log entry is gone. Entries are deleted once they are past their retention.'));

            return $this->resultRedirectFactory->create()->setPath('calmfox_smtp/log/index');
        }

        /** @var Page $page */
        $page = $this->pages->create();
        $page->setActiveMenu('Calmfox_Smtp::log');
        $page->getConfig()->getTitle()->prepend(__('Message to %1', $entry->getRecipients()));

        return $page;
    }

    private function load(int $entityId): ?LogEntry
    {
        if ($entityId <= 0) {
            return null;
        }

        $entry = $this->entries->create();
        $this->resource->load($entry, $entityId);

        return null === $entry->getId() ? null : $entry;
    }
}
