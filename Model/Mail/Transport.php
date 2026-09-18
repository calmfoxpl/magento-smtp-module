<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Mail;

use Calmfox\Smtp\Core\Diagnosis\Diagnosis;
use Calmfox\Smtp\Core\Log\Outcome;
use Calmfox\Smtp\Core\Log\Redaction;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Health\Monitor;
use Calmfox\Smtp\Model\Log\Writer;
use Laminas\Mail\Message as LaminasMessage;
use Magento\Email\Model\TransportFactory as MagentoTransportFactory;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\MessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The transport Magento hands every outgoing message to.
 *
 * It is a decorator, not a replacement, and the order of the decisions in `sendMessage()` is the
 * contract:
 *
 *  1. Magento's own "Disable Email Communications" wins over everything. Replacing the platform
 *     transport means inheriting its promises, and a shop that has switched sending off — a
 *     staging copy, most often — must not start sending because this module was installed.
 *  2. With the module switched off, Magento's transport does the work. Uninstalling is then a
 *     configuration change rather than a deployment.
 *  3. Otherwise we send, and record what happened: to the log, and to the health record that
 *     the warning in the panel is drawn from.
 *
 * Nothing about recording may cost a message. A failure to write the log row is a line in
 * `var/log`; the exception that reaches Magento is only ever the one about the message itself.
 */
class Transport implements TransportInterface
{
    public function __construct(
        private readonly MessageInterface $message,
        private readonly Config $config,
        private readonly SmtpTransportBuilder $builder,
        private readonly MagentoTransportFactory $magentoTransport,
        private readonly StoreManagerInterface $storeManager,
        private readonly Monitor $monitor,
        private readonly Writer $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getMessage(): MessageInterface
    {
        return $this->message;
    }

    public function sendMessage(): void
    {
        $storeId = $this->storeId();

        if ($this->sendingIsSwitchedOffInMagento($storeId)) {
            $this->record($storeId, null, Outcome::SUPPRESSED, 0, null, '');

            return;
        }

        $resolved = $this->config->resolve($storeId);
        if (!$resolved->isUsable()) {
            // Either the module is off, or its settings cannot produce a connection. Magento's
            // own transport is the honest fallback: whatever it manages, it is what the shop
            // would do without us.
            $this->magentoTransport->create(['message' => $this->message])->sendMessage();

            return;
        }

        $settings = $resolved->settings;
        $raw = (string) $this->message->getRawMessage();
        $started = microtime(true);

        try {
            $this->builder->build($settings, $this->clientName($storeId))->send($this->prepare($raw, $settings));
        } catch (\Throwable $failure) {
            $this->afterFailure($storeId, $settings, $failure, self::since($started));

            throw new MailException(new Phrase('The message could not be sent: %1', [$failure->getMessage()]), $failure);
        }

        $this->afterSuccess($storeId, $settings, self::since($started));
    }

    /**
     * The message as it will leave, with the sender overridden where that was asked for.
     *
     * Providers reject mail whose sender is not one of theirs, and a Magento installation has
     * sender addresses scattered across half a dozen settings and any number of modules.
     * Forcing one here is the difference between a shop that sends and an afternoon of finding
     * out which module used which address.
     */
    private function prepare(string $raw, MailSettings $settings): LaminasMessage
    {
        $message = LaminasMessage::fromString($raw);
        $message->setEncoding('utf-8');

        if (null !== $settings->fromEmail) {
            $message->setFrom($settings->fromEmail, $settings->fromName);
        } elseif (null !== $settings->fromName) {
            $from = $message->getFrom();
            foreach ($from as $address) {
                $message->setFrom($address->getEmail(), $settings->fromName);
                break;
            }
        }

        return $message;
    }

    private function afterSuccess(int $storeId, MailSettings $settings, int $ms): void
    {
        $this->guard(function () use ($storeId, $settings, $ms): void {
            $this->record($storeId, $settings, Outcome::SENT, $ms, null, '');
            $this->monitor->recordSendSuccess($storeId);
        });
    }

    private function afterFailure(int $storeId, MailSettings $settings, \Throwable $failure, int $ms): void
    {
        $this->guard(function () use ($storeId, $settings, $failure, $ms): void {
            $cause = Diagnosis::fromSendFailure($failure->getMessage());
            $this->record($storeId, $settings, Outcome::FAILED, $ms, $cause, $failure->getMessage());
            $this->monitor->recordSendFailure($storeId, $cause, $failure->getMessage());
        });

        $this->logger->error(
            'Calmfox SMTP: a message could not be sent.',
            ['endpoint' => $settings->endpoint(), 'error' => Redaction::apply($failure->getMessage(), [$settings->password])],
        );
    }

    private function record(int $storeId, ?MailSettings $settings, string $outcome, int $ms, ?string $cause, string $error): void
    {
        if (!$this->config->logEnabled($storeId)) {
            return;
        }

        $this->writer->record(
            $storeId,
            $settings ?? $this->config->resolve($storeId)->settings,
            (string) $this->message->getRawMessage(),
            $outcome,
            $ms,
            $cause,
            $error,
            // A body is only worth keeping for a message that could be sent again.
            Outcome::FAILED === $outcome && $this->config->logsBody($storeId),
        );
    }

    /**
     * Magento's own switch, honoured exactly as Magento honours it: the message is dropped and
     * nobody is told, because that is what the setting means.
     */
    private function sendingIsSwitchedOffInMagento(int $storeId): bool
    {
        $magento = $this->config->magentoMailSettings($storeId);
        $disabled = $magento['disable'] ?? false;

        return \is_bool($disabled)
            ? $disabled
            : \in_array(mb_strtolower(trim((string) $disabled)), ['1', 'true', 'yes'], true);
    }

    /** Bookkeeping must never be the reason a shop stops sending. */
    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: a send could not be recorded.', ['exception' => $error]);
        }
    }

    private function storeId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function clientName(int $storeId): string
    {
        try {
            $host = parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl(), \PHP_URL_HOST);
        } catch (\Throwable) {
            $host = null;
        }

        return \is_string($host) && '' !== $host ? $host : 'localhost';
    }

    private static function since(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
