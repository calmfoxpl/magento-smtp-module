<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Block\Adminhtml;

use Calmfox\Smtp\Core\Log\Outcome;
use Calmfox\Smtp\Model\LogEntry as Entry;
use Calmfox\Smtp\Model\LogEntryFactory;
use Calmfox\Smtp\Model\ResourceModel\LogEntry as EntryResource;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * One message from the log, in full.
 *
 * The page exists for the conversation that starts "the customer says they never got it". It
 * shows what was sent, where it went, what the server answered, and — where the message is
 * still stored — offers to send it again. The body is shown as source rather than rendered:
 * this is a page for finding out what happened, and rendering somebody else's HTML inside the
 * admin panel is a habit worth not having.
 */
class LogEntry extends Template
{
    protected $_template = 'Calmfox_Smtp::log/view.phtml';

    private ?Entry $entry = null;

    public function __construct(
        Context $context,
        private readonly LogEntryFactory $entries,
        private readonly EntryResource $resource,
        private readonly Wording $wording,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getEntry(): ?Entry
    {
        if (null !== $this->entry) {
            return $this->entry;
        }

        $entityId = (int) $this->getRequest()->getParam('entity_id');
        if ($entityId <= 0) {
            return null;
        }

        $entry = $this->entries->create();
        $this->resource->load($entry, $entityId);

        return $this->entry = (null === $entry->getId() ? null : $entry);
    }

    /** @return array<string, string> */
    public function getFacts(): array
    {
        $entry = $this->getEntry();
        if (null === $entry) {
            return [];
        }

        $facts = [
            (string) __('Sent at') => $this->formatDate((string) $entry->getCreatedAt(), \IntlDateFormatter::MEDIUM, true),
            (string) __('Outcome') => $this->getOutcomeLabel(),
            (string) __('From') => (string) $entry->getFromAddress(),
            (string) __('To') => (string) $entry->getRecipients(),
            (string) __('Subject') => (string) $entry->getSubject(),
            (string) __('Through') => (string) $entry->getEndpoint(),
            (string) __('Size') => (string) __('%1 bytes', (int) $entry->getMessageSize()),
            (string) __('Took') => (string) __('%1 ms', (int) $entry->getDurationMs()),
            (string) __('Attempts') => (string) (int) $entry->getAttempts(),
        ];

        if (null !== $entry->getResentAt()) {
            $facts[(string) __('Sent again at')] = $this->formatDate(
                (new \DateTime('@' . (int) $entry->getResentAt()))->format('Y-m-d H:i:s'),
                \IntlDateFormatter::MEDIUM,
                true,
            );
        }

        return $facts;
    }

    public function getOutcomeLabel(): string
    {
        return match ((string) ($this->getEntry()?->getOutcome() ?? '')) {
            Outcome::SENT => (string) __('Accepted by the server'),
            Outcome::SUPPRESSED => (string) __('Dropped, because Magento is set to send no e-mail'),
            default => (string) __('Not sent'),
        };
    }

    public function getFailureLines(): array
    {
        $entry = $this->getEntry();
        if (null === $entry || !$entry->failed()) {
            return [];
        }

        $lines = [];
        $cause = (string) $entry->getCause();
        if ('' !== $cause) {
            $lines[] = (string) $this->wording->cause($cause);
        }
        $error = (string) $entry->getError();
        if ('' !== $error) {
            $lines[] = (string) __('The server said: %1', $error);
        }

        return $lines;
    }

    public function getResendUrl(): string
    {
        return $this->getUrl('calmfox_smtp/log/resend', ['entity_id' => (int) ($this->getEntry()?->getId() ?? 0)]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('calmfox_smtp/log/index');
    }

    public function canResend(): bool
    {
        return true === $this->getEntry()?->canBeResent();
    }

    /** Why the button is not there, which is more use than no button and no explanation. */
    public function getResendNote(): string
    {
        $entry = $this->getEntry();
        if (null === $entry || !$entry->failed()) {
            return '';
        }
        if ($entry->canBeResent()) {
            return (string) __('Sending it again uses the settings as they are now, which is the point: what changed is the settings.');
        }

        return (string) __('The message itself was not kept, so it cannot be sent again. Turn on "Keep the message body" to make that possible for future failures.');
    }
}
