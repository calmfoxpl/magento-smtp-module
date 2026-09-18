<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The button that sends one real message.
 *
 * Separate from the connection check, and worded separately, because they answer different
 * questions and only one of them can be answered without spending a message: the check proves
 * the shop can reach the server and log in, this proves the server will take a message from this
 * sender. Where the message then lands is a question about the domain's DNS records, and the
 * wording says so rather than implying a green tick settles it.
 */
class TestButton extends Field
{
    protected $_template = 'Calmfox_Smtp::system/config/test-button.phtml';

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
        return $this->getUrl('calmfox_smtp/test/send', ['store' => $this->storeId()]);
    }

    public function getButtonHtml(): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class);
        $button->setData(['id' => 'calmfox-smtp-test', 'label' => __('Send a test message')]);

        return $button->toHtml();
    }

    public function storeId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }
}
