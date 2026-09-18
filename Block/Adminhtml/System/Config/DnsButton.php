<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The button that asks the shop's own domain what it publishes.
 *
 * Its answer is the one thing the connection check cannot give: whether the mail will be
 * believed at the other end. It is a button rather than something that happens on page load
 * because the system resolver has no timeout worth the name, and a settings screen that hangs
 * for five seconds would be a fault of its own.
 */
class DnsButton extends Field
{
    protected $_template = 'Calmfox_Smtp::system/config/dns-button.phtml';

    public function render(AbstractElement $element): string
    {
        $this->addData(['html_id' => $element->getHtmlId(), 'scope_label' => '']);

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('calmfox_smtp/dns/inspect', ['store' => $this->storeId()]);
    }

    public function getButtonHtml(): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class);
        $button->setData(['id' => 'calmfox-smtp-dns', 'label' => __('Check the sender domain')]);

        return $button->toHtml();
    }

    public function storeId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }
}
