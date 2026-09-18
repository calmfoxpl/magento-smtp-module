<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Controller\Adminhtml\Dns;

use Calmfox\Smtp\Core\Dns\Suggestion;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Dns\DomainCheck;
use Calmfox\Smtp\Model\Provider\ProviderLabel;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Asks the sender domain what it publishes, now, because somebody pressed a button.
 *
 * The one place in the module where a wait is honest: there is a person in front of the screen
 * who asked the question. It answers in the same shape as the connection check and the test
 * message, so the same browser module renders all three.
 *
 * It does two things at once, which is what the button is for: says whether the domain
 * authorises the provider that is configured, and — when the settings are still empty — says
 * what the domain looks like it is already set up for.
 */
class Inspect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Calmfox_Smtp::config';

    public function __construct(
        Action\Context $context,
        private readonly DomainCheck $domains,
        private readonly Config $config,
        private readonly Wording $wording,
        private readonly JsonFactory $results,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        if (!$this->config->dnsCheckEnabled($storeId)) {
            return $this->results->create()->setData([
                'ok' => false,
                'headline' => (string) __('Checking the sender domain is switched off.'),
                'lines' => [],
            ]);
        }

        $verdict = $this->domains->refresh($storeId);
        $lines = [];

        foreach ($verdict?->issues ?? [] as $issue) {
            $lines[] = (string) $this->wording->issue($issue);
        }
        foreach ($this->suggestionLines($storeId) as $line) {
            $lines[] = $line;
        }

        $atRisk = true === $verdict?->isDeliverabilityAtRisk();

        return $this->results->create()->setData([
            'ok' => !$atRisk,
            'headline' => $atRisk
                ? (string) $this->wording->deliverabilityHeadline($verdict?->domain)
                : (string) $this->headline($verdict?->domain),
            'lines' => array_values(array_unique($lines)),
        ]);
    }

    private function headline(?string $domain): \Magento\Framework\Phrase
    {
        if (null === $domain) {
            return __('Nothing was checked: we do not know which domain this shop sends as.');
        }

        return __('Nothing in what %1 publishes should stop the mail arriving.', $domain);
    }

    /**
     * What the domain says it is already set up for, offered as a question.
     *
     * Only worth saying while the settings are still empty or point elsewhere: telling somebody
     * who has just configured Brevo that their domain authorises Brevo is noise.
     *
     * @return list<string>
     */
    private function suggestionLines(int $storeId): array
    {
        $suggestion = $this->domains->suggest($storeId);
        if (!$suggestion->hasAnything()) {
            return [];
        }

        $settings = $this->config->resolve($storeId)->settings;
        $lines = [];

        if (null !== $suggestion->providerId && $suggestion->providerId !== $settings->providerId) {
            $lines[] = (string) __(
                'The domain authorises %1, which is not what is configured here. If that is the provider you mean to use, pick it above and its server will be filled in.',
                ProviderLabel::of($suggestion->providerId),
            );
        }
        if (null !== $suggestion->otherSender) {
            $lines[] = (string) __(
                'The domain authorises %1, which has no ready-made settings here. Its server, port and credentials have to be typed in by hand.',
                $suggestion->otherSender,
            );
        }
        if (Suggestion::FROM_SRV === $suggestion->source && null !== $suggestion->host && '' === $settings->host) {
            $lines[] = (string) __(
                'The domain publishes a submission service of its own: %1 on port %2. That is its own answer about its own mail, not a guess of ours.',
                $suggestion->host,
                (string) $suggestion->port,
            );
        }

        return $lines;
    }
}
