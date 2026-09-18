<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Alert;

use Calmfox\Smtp\Core\Alert\Alert;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Text\Wording;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\Sendmail;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The warning that leaves the building, sent deliberately the wrong way.
 *
 * There is an obvious problem with e-mailing somebody to tell them their e-mail is broken, and
 * the only honest answer is not to use the broken path: this goes out through the server's own
 * local mail command, not through the configured provider and not through Magento's transport —
 * which is, after all, us.
 *
 * That has a cost, and the panel says so rather than pretending otherwise: local mail is often
 * unconfigured, and when it is configured it often lands in a spam folder. It is a second
 * chance, not a guarantee, which is why the panel banner and the webhook exist alongside it.
 * The message is plain text, short, and repeats the one thing worth checking.
 */
class EmailChannel
{
    public function __construct(
        private readonly Config $config,
        private readonly Wording $wording,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $backendUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(Alert $alert, HealthState $state, MailSettings $settings, int $storeId): bool
    {
        $recipient = $this->config->alertRecipient($storeId);
        if ('' === $recipient) {
            return false;
        }

        try {
            $message = new Message();
            $message->setEncoding('utf-8');
            $message->addFrom($this->sender($storeId), $this->shopName($storeId));
            $message->addTo($recipient);
            $message->setSubject($this->subject($alert, $state, $settings, $storeId));
            $message->setBody($this->body($alert, $state, $settings));

            (new Sendmail())->send($message);

            return true;
        } catch (\Throwable $error) {
            // Expected on a server with no local mail command. The panel and the log still have it.
            $this->logger->warning('Calmfox SMTP: the alert e-mail could not be sent.', ['exception' => $error]);

            return false;
        }
    }

    private function subject(Alert $alert, HealthState $state, MailSettings $settings, int $storeId): string
    {
        $shop = $this->shopName($storeId);

        if (!$alert->isProblem()) {
            return (string) __('%1: sending e-mail works again', $shop);
        }

        return (string) __('%1: the shop cannot send e-mail (%2)', $shop, $this->wording->cause($state->cause));
    }

    private function body(Alert $alert, HealthState $state, MailSettings $settings): string
    {
        if (!$alert->isProblem()) {
            $lines = [
                (string) $this->wording->recovery($settings),
                '',
                (string) __('Nothing needs to be done.'),
            ];

            return implode("\n", $lines) . "\n";
        }

        $lines = [(string) $this->wording->headline($state, $settings), ''];
        foreach ($this->wording->explain($state, $settings) as $sentence) {
            $lines[] = (string) $sentence;
        }

        $lines[] = '';
        if (null !== $state->failingSince) {
            $lines[] = (string) __('Failing since: %1', date('Y-m-d H:i', $state->failingSince));
        }
        if ($state->sendFailures > 0) {
            $lines[] = (string) __('Messages that failed to go out: %1', $state->sendFailures);
        }
        $lines[] = (string) __('Settings and the full report: %1', $this->panelUrl());
        $lines[] = '';
        $lines[] = (string) __('This warning was sent through the server\'s local mail command rather than the configured provider, because the configured provider is the thing that is not working.');

        return implode("\n", $lines) . "\n";
    }

    /**
     * The sender is the shop's own general contact, because a bounce to an address nobody reads
     * is worse than no warning at all.
     */
    private function sender(int $storeId): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            'trans_email/ident_general/email',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ));

        return '' !== $configured ? $configured : $this->config->alertRecipient($storeId);
    }

    private function shopName(int $storeId): string
    {
        $name = trim((string) $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ));
        if ('' !== $name) {
            return $name;
        }

        try {
            return (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable) {
            return 'Magento';
        }
    }

    private function panelUrl(): string
    {
        try {
            return $this->backendUrl->getUrl('calmfox_smtp/health/index');
        } catch (\Throwable) {
            return '';
        }
    }
}
