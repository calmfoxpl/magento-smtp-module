<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Config\Source;

use Calmfox\Smtp\Core\Log\Outcome as LogOutcome;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The three outcomes, as the grid filters and displays them.
 *
 * "Accepted by the server" rather than "sent", because that is all a transport can honestly
 * claim: the server took the message. Whether a person received it is a question no log can
 * answer, and a column that said "sent" would invite the wrong conclusion.
 */
class Outcome implements OptionSourceInterface
{
    /** @return list<array{value: string, label: string}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => LogOutcome::SENT, 'label' => __('Accepted by the server')],
            ['value' => LogOutcome::FAILED, 'label' => __('Not sent')],
            ['value' => LogOutcome::SUPPRESSED, 'label' => __('Dropped by Magento')],
        ];
    }
}
