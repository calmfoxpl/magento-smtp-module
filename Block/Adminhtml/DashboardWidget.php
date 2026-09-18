<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Block\Adminhtml;

use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Health\HealthStore;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * One line on the dashboard, and only when there is something to say.
 *
 * A working shop sees nothing here: a dashboard that reports good news in six places is a
 * dashboard nobody reads. A shop that cannot send sees it at the top of the first page anybody
 * opens, next to the day's orders, which is the moment the news is worth most.
 */
class DashboardWidget extends Template
{
    protected $_template = 'Calmfox_Smtp::dashboard-widget.phtml';

    private ?HealthState $state = null;

    private int $storeId = 0;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly HealthStore $store,
        private readonly Wording $wording,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        return $this->shouldBeShown() ? parent::_toHtml() : '';
    }

    public function shouldBeShown(): bool
    {
        $state = $this->state();

        return $state->isBroken()
            && $this->config->warnsInPanel($this->storeId)
            && $this->_authorization->isAllowed('Calmfox_Smtp::health');
    }

    public function getHeadline(): string
    {
        return (string) $this->wording->headline($this->state(), $this->config->resolve($this->storeId)->settings);
    }

    /** @return list<string> */
    public function getExplanation(): array
    {
        $lines = [];
        foreach ($this->wording->explain($this->state(), $this->config->resolve($this->storeId)->settings) as $sentence) {
            $lines[] = (string) $sentence;
        }

        return $lines;
    }

    public function getReportUrl(): string
    {
        return $this->getUrl('calmfox_smtp/health/index', ['store' => $this->storeId]);
    }

    private function state(): HealthState
    {
        if (null !== $this->state) {
            return $this->state;
        }

        $worst = $this->store->worst();
        if (null === $worst) {
            return $this->state = new HealthState(status: Status::UNKNOWN);
        }

        [$this->storeId, $state] = $worst;

        return $this->state = $state;
    }
}
