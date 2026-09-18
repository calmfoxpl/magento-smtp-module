<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Mail;

use Calmfox\Smtp\Core\Diagnosis\Diagnosis;
use Calmfox\Smtp\Core\Log\Outcome;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Health\Monitor;
use Calmfox\Smtp\Model\Log\Writer;
use Laminas\Mail\Message;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

/**
 * A real message, sent on purpose, because a connection check is not a delivery check.
 *
 * The health check deliberately stops before `MAIL FROM`, which means it cannot tell anybody
 * whether a message would arrive — and the failures it cannot see are real ones: a sender
 * address the provider has not verified, an account still in a sandbox, a domain with no SPF
 * record whose mail is accepted and then dropped. So the panel has a button that sends one
 * message, once, when a person asks for it, and the message says where to look next.
 *
 * It goes through the same builder as everything else. A test that took a different path would
 * be a test of the wrong thing.
 */
class TestSender
{
    public function __construct(
        private readonly Config $config,
        private readonly SmtpTransportBuilder $builder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Writer $writer,
        private readonly Monitor $monitor,
    ) {
    }

    /**
     * @throws LocalizedException
     *
     * @return int how long the whole thing took, in milliseconds
     */
    public function send(string $recipient, int $storeId = 0): int
    {
        if (false === filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('%1 is not an e-mail address.', $recipient));
        }

        $resolved = $this->config->resolve($storeId);
        if (!$resolved->isUsable()) {
            throw new LocalizedException(__('The settings are not complete enough to send anything yet.'));
        }

        $settings = $resolved->settings;
        $from = $settings->fromEmail ?? trim((string) $this->scopeConfig->getValue(
            'trans_email/ident_general/email',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ));

        if ('' === $from) {
            throw new LocalizedException(__('There is no sender address to send from. Set one in the general contact, or override it here.'));
        }

        $message = new Message();
        $message->setEncoding('utf-8');
        $message->addFrom($from, $settings->fromName);
        $message->addTo($recipient);
        $message->setSubject((string) __('Test message from your shop'));
        $message->setBody(implode("\n", [
            (string) __('This message was sent from the mail settings of your shop.'),
            '',
            (string) __('Sent through: %1', $settings->endpoint()),
            (string) __('Sender: %1', $from),
            '',
            (string) __('If it arrived in a spam folder rather than an inbox, the settings are right and the domain\'s SPF, DKIM and DMARC records are what to look at next.'),
        ]) . "\n");

        $raw = $message->toString();
        $started = microtime(true);

        try {
            $this->builder->build($settings)->send($message);
        } catch (\Throwable $failure) {
            $cause = Diagnosis::fromSendFailure($failure->getMessage());
            $milliseconds = (int) round((microtime(true) - $started) * 1000);

            if ($this->config->logEnabled($storeId)) {
                $this->writer->record($storeId, $settings, $raw, Outcome::FAILED, $milliseconds, $cause, $failure->getMessage());
            }
            $this->monitor->recordSendFailure($storeId, $cause, $failure->getMessage());

            throw new LocalizedException(__('The message was not accepted: %1', $failure->getMessage()), $failure);
        }

        $milliseconds = (int) round((microtime(true) - $started) * 1000);
        if ($this->config->logEnabled($storeId)) {
            $this->writer->record($storeId, $settings, $raw, Outcome::SENT, $milliseconds);
        }
        $this->monitor->recordSendSuccess($storeId);

        return $milliseconds;
    }
}
