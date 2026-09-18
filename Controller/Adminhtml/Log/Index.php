<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/** The grid of what the shop tried to send. */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::log';

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
        $page->setActiveMenu('Calmfox_Smtp::log');
        $page->getConfig()->getTitle()->prepend(__('Sent messages'));

        return $page;
    }
}
