<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Notification;

use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Health\HealthStore;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;

/**
 * The red bar across the top of every admin page.
 *
 * This is the channel that actually works. An e-mail about broken e-mail may not arrive, a
 * webhook needs somebody to have set one up, a log line needs a monitor — but an administrator
 * who logs in to process yesterday's orders cannot miss a bar that says the shop has not been
 * able to send a confirmation since Tuesday morning.
 *
 * The identity is derived from what is wrong rather than being a constant, which is what makes
 * Magento's "dismiss" behave the way a person expects: dismissing the warning about a refused
 * password hides that warning, and a different failure tomorrow is a new bar rather than a
 * silence. It reads the stored verdict and never checks anything itself: an admin page must not
 * wait on a mail server.
 */
class SystemMessage implements MessageInterface
{
    private ?HealthState $state = null;

    private int $storeId = 0;

    public function __construct(
        private readonly Config $config,
        private readonly HealthStore $store,
        private readonly Wording $wording,
        private readonly UrlInterface $url,
    ) {
    }

    public function getIdentity(): string
    {
        $state = $this->state();

        return hash('sha256', 'calmfox_smtp_health:' . $state->status . ':' . $state->cause . ':' . $state->fingerprint);
    }

    public function isDisplayed(): bool
    {
        $state = $this->state();

        return $state->isBroken() && $this->config->warnsInPanel($this->storeId);
    }

    public function getText(): Phrase
    {
        $state = $this->state();
        $settings = $this->config->resolve($this->storeId)->settings;

        $sentences = [(string) $this->wording->headline($state, $settings)];
        foreach ($this->wording->explain($state, $settings) as $sentence) {
            $sentences[] = (string) $sentence;
        }

        return __(
            '%1 <a href="%2">Open the mail health report</a>.',
            implode(' ', $sentences),
            $this->url->getUrl('calmfox_smtp/health/index'),
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_CRITICAL;
    }

    /** Whichever store view is worst off; a shop with one broken sender is a broken shop. */
    private function state(): HealthState
    {
        if (null !== $this->state) {
            return $this->state;
        }

        $worst = $this->store->worst();
        if (null === $worst) {
            return $this->state = new HealthState();
        }

        [$this->storeId, $state] = $worst;

        return $this->state = $state;
    }
}
