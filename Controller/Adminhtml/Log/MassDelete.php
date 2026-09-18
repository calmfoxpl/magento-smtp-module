<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Log;

use Calmfox\Smtp\Model\ResourceModel\LogEntry\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Deletes the selected entries.
 *
 * Worth having a button for rather than leaving to retention: somebody who has just realised
 * how much customer correspondence is in this table should be able to act on that in the panel,
 * on the rows they are looking at, without waiting for a nightly job or reaching for the
 * console.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::log';

    public function __construct(
        Action\Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collections,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        try {
            $collection = $this->filter->getCollection($this->collections->create());
            $deleted = 0;
            foreach ($collection as $entry) {
                $entry->delete();
                ++$deleted;
            }
            $this->messageManager->addSuccessMessage((string) __('%1 entries deleted.', $deleted));
        } catch (\Throwable $failure) {
            $this->messageManager->addErrorMessage($failure->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('calmfox_smtp/log/index');
    }
}
