<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Alert;

use Calmfox\Smtp\Core\Alert\Alert;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Log\Redaction;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The warning that reaches somebody who is not looking at the panel.
 *
 * Shaped so that one URL covers the three places these actually go: Slack, Teams and a webhook
 * of one's own. All three are happy with a JSON body that has a `text` field, so `text` carries
 * the human sentence and everything else sits beside it for whoever wants to parse it.
 *
 * Two deliberate restraints. The payload goes through the same redaction as everything else,
 * because a webhook URL usually belongs to a chat room that outlives the incident. And a webhook
 * that does not answer is logged and forgotten rather than retried, because this is called from
 * a cron run and from the middle of sending an order confirmation.
 */
class WebhookChannel
{
    private const TIMEOUT = 5;

    public function __construct(
        private readonly Config $config,
        private readonly Wording $wording,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(Alert $alert, HealthState $state, MailSettings $settings, int $storeId): bool
    {
        $url = $this->config->webhookUrl($storeId);
        if ('' === $url || !str_starts_with($url, 'https://')) {
            // Plain http would put the shop's state on the wire in the clear, and every service
            // that offers webhooks offers them over https.
            return false;
        }

        try {
            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->post($url, $this->json->serialize($this->payload($alert, $state, $settings, $storeId)));
            $status = $this->curl->getStatus();

            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Calmfox SMTP: the webhook answered %1.', ['status' => $status]);

                return false;
            }

            return true;
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the webhook could not be reached.', ['exception' => $error]);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function payload(Alert $alert, HealthState $state, MailSettings $settings, int $storeId): array
    {
        $text = $alert->isProblem()
            ? (string) $this->wording->headline($state, $settings)
            : (string) $this->wording->recovery($settings);

        $explanation = [];
        if ($alert->isProblem()) {
            foreach ($this->wording->explain($state, $settings) as $sentence) {
                $explanation[] = (string) $sentence;
            }
        }

        return Redaction::applyToArray([
            'text' => $text,
            'smtp' => [
                'event' => $alert->kind,
                'reminder' => $alert->isReminder,
                'status' => $state->status,
                'cause' => $state->cause,
                'stage' => $state->stage,
                'reply_code' => $state->replyCode,
                'reply' => $state->reply,
                'provider' => $settings->providerId,
                'endpoint' => $settings->endpoint(),
                'store_id' => $storeId,
                'failing_since' => null === $state->failingSince ? null : date(\DATE_ATOM, $state->failingSince),
                'last_ok_at' => null === $state->lastOkAt ? null : date(\DATE_ATOM, $state->lastOkAt),
                'failed_checks' => $state->consecutiveFailures,
                'failed_messages' => $state->sendFailures,
                'explanation' => $explanation,
            ],
        ], array_values(array_filter([$settings->password])));
    }
}
