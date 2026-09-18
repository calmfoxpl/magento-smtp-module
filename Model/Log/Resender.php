<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Log;

use Calmfox\Smtp\Core\Diagnosis\Diagnosis;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Health\Monitor;
use Calmfox\Smtp\Model\LogEntry;
use Calmfox\Smtp\Model\Mail\SmtpTransportBuilder;
use Laminas\Mail\Message as LaminasMessage;
use Magento\Framework\Exception\LocalizedException;

/**
 * Sends a failed message again, from the panel.
 *
 * The case this exists for is the ordinary one: an API key expired at nine, somebody noticed at
 * eleven, and the eleven order confirmations in between are sitting in the log. Fixing the key
 * does not send them, and asking a customer to place the order again is not a plan.
 *
 * It sends the stored message verbatim — the same headers, the same body, the same recipients —
 * through whatever the settings say now, which is the point: what changed is the settings. It
 * refuses politely where there is nothing to send, because a log kept without bodies is a
 * legitimate choice and a button that silently does nothing is not.
 */
class Resender
{
    public function __construct(
        private readonly Config $config,
        private readonly SmtpTransportBuilder $builder,
        private readonly Writer $writer,
        private readonly Monitor $monitor,
    ) {
    }

    /**
     * @throws LocalizedException when there is nothing to send, or the settings cannot send it
     */
    public function resend(LogEntry $entry): void
    {
        $raw = (string) $entry->getRawMessage();
        if ('' === $raw) {
            throw new LocalizedException(__('This message was not kept, so it cannot be sent again. Entries are only kept in full while "Keep the message body" is on.'));
        }

        $storeId = (int) $entry->getStoreId();
        $resolved = $this->config->resolve($storeId);
        if (!$resolved->isUsable()) {
            throw new LocalizedException(__('The settings cannot send anything at the moment, so there is no point trying. Fix the settings first.'));
        }

        $settings = $resolved->settings;
        $secrets = array_values(array_filter([$settings->password]));

        try {
            $this->builder->build($settings)->send(LaminasMessage::fromString($raw));
        } catch (\Throwable $failure) {
            $cause = Diagnosis::fromSendFailure($failure->getMessage());
            $this->writer->recordResend($entry, false, $cause, $failure->getMessage(), $secrets);
            $this->monitor->recordSendFailure($storeId, $cause, $failure->getMessage());

            throw new LocalizedException(__('It failed again: %1', $failure->getMessage()), $failure);
        }

        $this->writer->recordResend($entry, true, null, '', $secrets);
        $this->monitor->recordSendSuccess($storeId);
    }
}
