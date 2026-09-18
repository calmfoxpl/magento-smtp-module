<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Health;

use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Health\Monitor;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Checks now, and says everything it found.
 *
 * Always a fresh check: this is a button somebody pressed, and "now" is what they meant. The
 * answer is a headline and a list of sentences rather than a status code, because what makes
 * this button worth pressing is the sentence after the failure — the one that names the thing
 * to go and look at.
 *
 * It also passes on the settings warnings, which is where they are most useful: a shop with
 * STARTTLS on port 465 gets told that here, next to the two fields in question.
 */
class Check extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::health';

    public function __construct(
        Action\Context $context,
        private readonly Monitor $monitor,
        private readonly Config $config,
        private readonly Wording $wording,
        private readonly JsonFactory $results,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $resolved = $this->config->resolve($storeId);
        $lines = [];

        foreach ($resolved->issues as $issue) {
            $lines[] = (string) $this->wording->issue($issue);
        }

        if (!$resolved->settings->enabled) {
            return $this->results->create()->setData([
                'ok' => false,
                'headline' => (string) __('Sending through this module is switched off.'),
                'lines' => $lines,
            ]);
        }

        $state = $this->monitor->check($storeId, true);
        $ok = Status::OK === $state->status;

        foreach ($this->wording->explain($state, $resolved->settings) as $sentence) {
            $lines[] = (string) $sentence;
        }
        if ($ok) {
            $lines[] = (string) __('Checked in %1 ms. A connection that works is not a delivery that works: send a test message to be sure of that.', $state->durationMs);
        }

        return $this->results->create()->setData([
            'ok' => $ok,
            'headline' => (string) $this->wording->headline($state, $resolved->settings),
            'lines' => array_values(array_unique($lines)),
        ]);
    }
}
