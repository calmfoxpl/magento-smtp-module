<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The button that answers the only question this screen is really about.
 *
 * It checks what is saved, not what is on the screen, and the template says so: a shopkeeper who
 * presses it after editing a field and before saving would otherwise be told about the previous
 * password and conclude the module is broken. Everything else here is preparation for pressing
 * it.
 */
class CheckButton extends Field
{
    protected $_template = 'Calmfox_Smtp::system/config/check-button.phtml';

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
        return $this->getUrl('calmfox_smtp/health/check', ['store' => $this->storeId()]);
    }

    public function getButtonHtml(): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class);
        $button->setData(['id' => 'calmfox-smtp-check', 'label' => __('Check the connection now')]);

        return $button->toHtml();
    }

    public function storeId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }
}
