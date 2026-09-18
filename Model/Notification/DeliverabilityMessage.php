<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Notification;

use Calmfox\Smtp\Core\Dns\DomainVerdict;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Dns\DomainCheck;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;

/**
 * The second bar in the panel, and deliberately not the red one.
 *
 * "The shop cannot send" and "the shop is sending into a spam folder" are different jobs for
 * different people on different clocks, and a module that shouted them in the same colour would
 * teach an administrator to ignore both. So this one is a warning: it appears only when the
 * sender domain's records will actually cost the shop delivered mail, and only from what the
 * cron job has already looked up — no admin page ever waits on a nameserver.
 */
class DeliverabilityMessage implements MessageInterface
{
    private ?DomainVerdict $verdict = null;

    private bool $looked = false;

    public function __construct(
        private readonly Config $config,
        private readonly DomainCheck $domains,
        private readonly Wording $wording,
        private readonly UrlInterface $url,
    ) {
    }

    public function getIdentity(): string
    {
        $verdict = $this->verdict();

        return hash('sha256', 'calmfox_smtp_deliverability:' . ($verdict?->domain ?? '') . ':' . implode(',', $verdict?->codes() ?? []));
    }

    public function isDisplayed(): bool
    {
        $verdict = $this->verdict();
        if (null === $verdict || !$verdict->isDeliverabilityAtRisk()) {
            return false;
        }

        return $this->config->warnsInPanel() && $this->config->isEnabled();
    }

    public function getText(): Phrase
    {
        $verdict = $this->verdict();
        $sentences = [(string) $this->wording->deliverabilityHeadline($verdict?->domain)];

        foreach ($verdict?->issues ?? [] as $issue) {
            $sentences[] = (string) $this->wording->issue($issue);
        }

        return __(
            '%1 <a href="%2">Open the mail health report</a>.',
            implode(' ', $sentences),
            $this->url->getUrl('calmfox_smtp/health/index'),
        );
    }

    /** A warning rather than an alarm: the shop is sending, and somebody has a DNS change to make. */
    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }

    private function verdict(): ?DomainVerdict
    {
        if (!$this->looked) {
            $this->looked = true;
            $this->verdict = $this->domains->stored();
        }

        return $this->verdict;
    }
}
