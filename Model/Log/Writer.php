<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Log;

use Calmfox\Smtp\Core\Log\MessageSummary;
use Calmfox\Smtp\Core\Log\Outcome;
use Calmfox\Smtp\Core\Log\Redaction;
use Calmfox\Smtp\Core\Log\Retention;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Model\LogEntry;
use Calmfox\Smtp\Model\LogEntryFactory;
use Calmfox\Smtp\Model\ResourceModel\LogEntry as LogEntryResource;
use Psr\Log\LoggerInterface;

/**
 * Writes the log row, and never at the expense of the message.
 *
 * Two rules. The first is that nothing in here may throw: the caller is a mail transport in the
 * middle of sending an order confirmation, and a full disk or a missing table must not become a
 * lost e-mail. The second is that the password never reaches the table — not through the error
 * column, which is where a failed SMTP conversation would otherwise leave a base64 copy of it.
 */
class Writer
{
    public function __construct(
        private readonly LogEntryFactory $entries,
        private readonly LogEntryResource $resource,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(
        int $storeId,
        MailSettings $settings,
        string $rawMessage,
        string $outcome,
        int $durationMs,
        ?string $cause = null,
        string $error = '',
        bool $keepBody = false,
    ): ?LogEntry {
        try {
            $summary = MessageSummary::fromRaw($rawMessage);
            $secrets = array_values(array_filter([$settings->password]));

            $entry = $this->entries->create();
            $entry->addData([
                'store_id' => $storeId,
                'outcome' => \in_array($outcome, Outcome::ALL, true) ? $outcome : Outcome::FAILED,
                'provider' => $settings->providerId,
                'endpoint' => $settings->endpoint(),
                'from_address' => mb_substr($summary->from, 0, 255),
                'recipients' => $summary->recipientsForColumn(),
                'subject' => $summary->subjectForColumn(),
                'message_size' => $summary->bytes,
                'duration_ms' => $durationMs,
                'attempts' => 1,
                'cause' => $cause,
                'error' => '' === $error ? null : mb_substr(Redaction::apply($error, $secrets), 0, 1000),
                'raw_message' => Retention::shouldStoreBody($keepBody, $summary->bytes)
                    ? Redaction::apply($rawMessage, $secrets)
                    : null,
            ]);
            $this->resource->save($entry);

            return $entry;
        } catch (\Throwable $error) {
            // The log is a convenience. The message is the job.
            $this->logger->warning('Calmfox SMTP: a message could not be logged.', ['exception' => $error]);

            return null;
        }
    }

    /** A message sent again from the panel: the same row, one more attempt. */
    public function recordResend(LogEntry $entry, bool $sent, ?string $cause, string $error, array $secrets = []): void
    {
        try {
            $entry->addData([
                'outcome' => $sent ? Outcome::SENT : Outcome::FAILED,
                'attempts' => (int) $entry->getAttempts() + 1,
                'resent_at' => time(),
                'cause' => $sent ? null : $cause,
                'error' => $sent || '' === $error ? null : mb_substr(Redaction::apply($error, $secrets), 0, 1000),
            ]);
            $this->resource->save($entry);

            if ($sent) {
                // It has gone. What is left is personal data with nothing left to do.
                $this->resource->forgetBody((int) $entry->getId());
            }
        } catch (\Throwable $failure) {
            $this->logger->warning('Calmfox SMTP: a resend could not be recorded.', ['exception' => $failure]);
        }
    }
}
