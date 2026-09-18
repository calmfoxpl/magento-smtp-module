<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Config\Source;

use Calmfox\Smtp\Core\Provider\ProviderCatalog;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The provider list in the configuration screen.
 *
 * Brand names are not translated — "Brevo" is Brevo in every language — so the labels come
 * straight from the catalogue. Only "a server of my own" is a phrase, and it sits at the top
 * because that is the honest default for a shop that has not chosen yet.
 */
class Provider implements OptionSourceInterface
{
    /** @return list<array{value: string, label: string}> */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (ProviderCatalog::all() as $provider) {
            $options[] = [
                'value' => $provider->id,
                'label' => $provider->isCustom() ? (string) __('A server of my own') : $provider->label,
            ];
        }

        return $options;
    }
}
