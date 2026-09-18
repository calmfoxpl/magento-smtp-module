<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Log;

use Calmfox\Smtp\Model\Log\Resender;
use Calmfox\Smtp\Model\LogEntryFactory;
use Calmfox\Smtp\Model\ResourceModel\LogEntry as LogEntryResource;
use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * Sends a failed message again.
 *
 * The scenario this is for: a key expired at nine, somebody noticed at eleven, and eleven order
 * confirmations are sitting in the log. It is a POST because it sends real e-mail to real
 * customers, and it needs its own permission for the same reason.
 */
class Resend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::log';

    public function __construct(
        Action\Context $context,
        private readonly LogEntryFactory $entries,
        private readonly LogEntryResource $resource,
        private readonly Resender $resender,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $entry = $this->entries->create();
        $this->resource->load($entry, $entityId);

        if (null === $entry->getId()) {
            $this->messageManager->addErrorMessage((string) __('That log entry is gone. Entries are deleted once they are past their retention.'));

            return $redirect->setPath('calmfox_smtp/log/index');
        }

        try {
            $this->resender->resend($entry);
            $this->messageManager->addSuccessMessage((string) __('The message to %1 has been sent.', $entry->getRecipients()));
        } catch (\Throwable $failure) {
            $this->messageManager->addErrorMessage($failure->getMessage());
        }

        return $redirect->setPath('calmfox_smtp/log/view', ['entity_id' => $entityId]);
    }
}
