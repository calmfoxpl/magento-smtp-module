<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Config\Source;

use Calmfox\Smtp\Core\Settings\Encryption as EncryptionSetting;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The three encryption choices, labelled by what they mean rather than by their initials.
 * "TLS" and "SSL" are the same thing to almost everybody; the port they belong on is the part
 * people actually need to get right, so that is what the label says.
 */
class Encryption implements OptionSourceInterface
{
    /** @return list<array{value: string, label: string}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => EncryptionSetting::TLS, 'label' => __('STARTTLS — usually port 587')],
            ['value' => EncryptionSetting::SSL, 'label' => __('TLS from the first byte — usually port 465')],
            ['value' => EncryptionSetting::NONE, 'label' => __('None — only for a relay on the same machine')],
        ];
    }
}
