<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Config\Source;

use Calmfox\Smtp\Core\Settings\AuthMethod as AuthMethodSetting;
use Magento\Framework\Data\OptionSourceInterface;

class AuthMethod implements OptionSourceInterface
{
    /** @return list<array{value: string, label: string}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => AuthMethodSetting::AUTO, 'label' => __('Whatever the provider expects (recommended)')],
            ['value' => AuthMethodSetting::LOGIN, 'label' => __('LOGIN')],
            ['value' => AuthMethodSetting::PLAIN, 'label' => __('PLAIN')],
            ['value' => AuthMethodSetting::CRAM_MD5, 'label' => __('CRAM-MD5')],
            ['value' => AuthMethodSetting::NONE, 'label' => __('None — the server accepts us by address')],
        ];
    }
}
