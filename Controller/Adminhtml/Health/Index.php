<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Health;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/** The report: what the last check found, and what to do about it. */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::health';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $pages,
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->pages->create();
        $page->setActiveMenu('Calmfox_Smtp::health');
        $page->getConfig()->getTitle()->prepend(__('Sending health'));

        return $page;
    }
}
