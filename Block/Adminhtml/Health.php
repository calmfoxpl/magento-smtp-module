<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Block\Adminhtml;

use Calmfox\Smtp\Core\Dns\DomainVerdict;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Core\Settings\Encryption;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Core\Settings\ResolvedSettings;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Dns\DomainCheck;
use Calmfox\Smtp\Model\Provider\ProviderLabel;
use Calmfox\Smtp\Model\Health\HealthStore;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The report page.
 *
 * It is written to be read by somebody who has just been told the shop cannot send e-mail and
 * does not yet know what SMTP stands for, and to be forwarded to somebody who does. So it says
 * the state in a sentence, then the one thing to check, then the server's own words — and it
 * repeats, every time, that a working connection is not a proven delivery. That sentence is the
 * difference between this page and the kind of green tick that costs a shop a day of orders.
 *
 * Nothing here dials a mail server. The page shows the stored verdict; the button asks for a
 * new one.
 */
class Health extends Template
{
    protected $_template = 'Calmfox_Smtp::health.phtml';

    private ?ResolvedSettings $resolved = null;

    private ?HealthState $state = null;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly HealthStore $store,
        private readonly DomainCheck $domains,
        private readonly Wording $wording,
        private readonly StoreManagerInterface $storeManager,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }

    public function getState(): HealthState
    {
        return $this->state ??= $this->store->load($this->getStoreId());
    }

    public function getSettings(): MailSettings
    {
        return $this->getResolved()->settings;
    }

    public function getResolved(): ResolvedSettings
    {
        return $this->resolved ??= $this->config->resolve($this->getStoreId());
    }

    public function isEnabled(): bool
    {
        return $this->getSettings()->enabled;
    }

    public function getHeadline(): string
    {
        return (string) $this->wording->headline($this->getState(), $this->getSettings());
    }

    public function getStatusLabel(): string
    {
        return (string) $this->wording->status($this->getState()->status);
    }

    /** The one class name the page's colour comes from. */
    public function getStatusModifier(): string
    {
        return match ($this->getState()->status) {
            Status::OK => 'ok',
            Status::WARN => 'warn',
            Status::FAIL => 'fail',
            default => 'unknown',
        };
    }

    /** @return list<string> what happened, the thing to check, and what the server said */
    public function getExplanation(): array
    {
        if (Status::UNKNOWN === $this->getState()->status) {
            return [(string) __('Nothing has been checked yet. The check runs on cron, and the button below runs it now.')];
        }

        $lines = [];
        foreach ($this->wording->explain($this->getState(), $this->getSettings()) as $sentence) {
            $lines[] = (string) $sentence;
        }

        return $lines;
    }

    /** @return list<array{severity: string, text: string}> */
    public function getIssues(): array
    {
        $issues = [];
        foreach ($this->getResolved()->issues as $issue) {
            $issues[] = ['severity' => $issue->severity, 'text' => (string) $this->wording->issue($issue)];
        }

        return $issues;
    }

    /** @return array<string, string> the facts, as label and value, in reading order */
    public function getFacts(): array
    {
        $state = $this->getState();
        $settings = $this->getSettings();

        $facts = [
            (string) __('Provider') => ProviderLabel::of($this->getSettings()->providerId),
            (string) __('Server') => '' === $settings->host ? (string) __('not set') : $settings->endpoint(),
            (string) __('Encryption') => $this->encryptionLabel(),
            (string) __('Logs in as') => $settings->usesAuthentication()
                ? $settings->username
                : (string) __('nothing is sent; the server accepts us by address'),
            (string) __('Last checked') => $this->when($state->checkedAt > 0 ? $state->checkedAt : null),
            (string) __('Last worked') => $this->when($state->lastOkAt),
            (string) __('Last message sent') => $this->when($state->lastSentAt),
        ];

        if ($state->checkedAt > 0) {
            $facts[(string) __('The check took')] = (string) __('%1 ms', $state->durationMs);
        }
        if (null !== $state->failingSince) {
            $facts[(string) __('Failing since')] = $this->when($state->failingSince);
        }
        if ($state->consecutiveFailures > 0) {
            $facts[(string) __('Failed checks in a row')] = (string) $state->consecutiveFailures;
        }
        if ($state->sendFailures > 0) {
            $facts[(string) __('Messages that failed to go out')] = (string) $state->sendFailures;
        }
        if (null !== $state->notifiedCause) {
            $facts[(string) __('Somebody was told')] = $this->when($state->notifiedAt);
        }

        return $facts;
    }

    /**
     * Where a warning would go if one were raised now.
     *
     * Shown because the commonest failure of a warning system is that nobody set it up: a shop
     * with every channel switched off has a health check that talks to itself.
     *
     * @return list<string>
     */
    public function getChannels(): array
    {
        $storeId = $this->getStoreId();
        $channels = [];

        if ($this->config->warnsInPanel($storeId)) {
            $channels[] = (string) __('a bar across the admin panel');
        }
        if ($this->config->warnsByEmail($storeId)) {
            $channels[] = (string) __('an e-mail to %1', $this->config->alertRecipient($storeId));
        }
        if ($this->config->warnsByWebhook($storeId)) {
            $channels[] = (string) __('a webhook');
        }
        $channels[] = (string) __('a line in the log, and the exit code of bin/magento calmfox:smtp:health');

        return $channels;
    }

    /**
     * Every store view, so that a shop sending from four addresses can see all four.
     *
     * @return list<array{id: int, name: string, status: string, label: string, endpoint: string, modifier: string}>
     */
    public function getStoreViews(): array
    {
        $states = $this->store->all();
        $rows = [];

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $settings = $this->config->resolve($storeId)->settings;
            if (!$settings->enabled) {
                continue;
            }

            $state = $states[$storeId] ?? new HealthState();
            $rows[] = [
                'id' => $storeId,
                'name' => (string) $store->getName(),
                'status' => $state->status,
                'label' => (string) $this->wording->status($state->status),
                'endpoint' => $settings->endpoint(),
                'modifier' => match ($state->status) {
                    Status::OK => 'ok',
                    Status::WARN => 'warn',
                    Status::FAIL => 'fail',
                    default => 'unknown',
                },
            ];
        }

        return $rows;
    }

    // ── what the sender domain publishes ─────────────────────────────────────

    public function isDomainCheckEnabled(): bool
    {
        return $this->config->dnsCheckEnabled($this->getStoreId());
    }

    /** The stored verdict only. This page never waits on a nameserver. */
    public function getDomainVerdict(): ?DomainVerdict
    {
        return $this->domains->stored($this->getStoreId());
    }

    public function getDomainHeadline(): string
    {
        $verdict = $this->getDomainVerdict();
        if (null === $verdict) {
            return (string) __('The sender domain has not been checked yet.');
        }
        if ($verdict->isDeliverabilityAtRisk()) {
            return (string) $this->wording->deliverabilityHeadline($verdict->domain);
        }
        if (null === $verdict->domain) {
            return (string) __('Nothing was checked: we do not know which domain this shop sends as.');
        }

        return (string) __('Nothing in what %1 publishes should stop the mail arriving.', $verdict->domain);
    }

    /** @return array<string, string> */
    public function getDomainFacts(): array
    {
        $verdict = $this->getDomainVerdict();
        if (null === $verdict || null === $verdict->domain) {
            return [];
        }

        $facts = [
            (string) __('Sends as') => $verdict->domain,
            (string) __('SPF') => (string) $this->wording->domainFact('spf', $verdict->asked ? $verdict->spfFound : null),
        ];

        if ($verdict->spfFound) {
            $facts[(string) __('The provider in SPF')] = (string) $this->wording->domainFact('spf_provider', $verdict->spfAuthorizesProvider);
            $facts[(string) __('SPF costs')] = (string) __('%1 of the 10 DNS lookups a receiver allows', $verdict->spfLookupCost);
        }

        $facts[(string) __('DKIM')] = (string) $this->wording->domainFact('dkim', $verdict->dkimFound);
        $facts[(string) __('DMARC')] = null === $verdict->dmarcPolicy
            ? (string) $this->wording->domainFact('dmarc', false)
            : $verdict->dmarcPolicy;

        if (null !== $verdict->otherSender) {
            $facts[(string) __('Also authorised')] = $verdict->otherSender;
        }

        return $facts;
    }

    /** @return list<array{severity: string, text: string}> */
    public function getDomainIssues(): array
    {
        $issues = [];
        foreach ($this->getDomainVerdict()?->issues ?? [] as $issue) {
            $issues[] = ['severity' => $issue->severity, 'text' => (string) $this->wording->issue($issue)];
        }

        return $issues;
    }

    public function getDomainUrl(): string
    {
        return $this->getUrl('calmfox_smtp/dns/inspect', ['store' => $this->getStoreId()]);
    }

    public function getCheckUrl(): string
    {
        return $this->getUrl('calmfox_smtp/health/check', ['store' => $this->getStoreId()]);
    }

    public function getTestUrl(): string
    {
        return $this->getUrl('calmfox_smtp/test/send', ['store' => $this->getStoreId()]);
    }

    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'calmfox_smtp']);
    }

    public function getLogUrl(): string
    {
        return $this->getUrl('calmfox_smtp/log/index');
    }

    public function getTestRecipient(): string
    {
        return $this->config->testRecipient($this->getStoreId());
    }

    private function encryptionLabel(): string
    {
        return match ($this->getSettings()->encryption) {
            Encryption::TLS => (string) __('STARTTLS'),
            Encryption::SSL => (string) __('TLS from the first byte'),
            default => (string) __('none'),
        };
    }

    private function when(?int $timestamp): string
    {
        if (null === $timestamp || $timestamp <= 0) {
            return (string) __('never');
        }

        return $this->formatDate(
            (new \DateTime('@' . $timestamp))->format('Y-m-d H:i:s'),
            \IntlDateFormatter::MEDIUM,
            true,
        );
    }
}
