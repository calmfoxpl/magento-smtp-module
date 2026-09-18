<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * The link at the end of each row.
 *
 * Only one action, deliberately: opening the entry. Sending a message again is a real e-mail to
 * a real customer, so it lives on the entry's own page behind a form — not one careless click
 * away in a grid, where the row under the cursor is not always the row that was meant.
 */
class Actions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $url,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     *
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['entity_id'])) {
                continue;
            }
            $item[$this->getData('name')]['view'] = [
                'href' => $this->url->getUrl('calmfox_smtp/log/view', ['entity_id' => $item['entity_id']]),
                'label' => __('Open'),
            ];
        }

        return $dataSource;
    }
}
