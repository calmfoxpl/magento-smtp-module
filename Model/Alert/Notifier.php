<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Alert;

use Calmfox\Smtp\Core\Alert\Alert;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Log\Redaction;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Text\Wording;
use Psr\Log\LoggerInterface;

/**
 * Says the thing, everywhere it was asked to be said.
 *
 * The four channels are not alternatives, they are four different people: the administrator who
 * will open the panel this afternoon (the banner, which needs nothing from this class — it reads
 * the health record), whoever gets the e-mail, whichever chat room the webhook points at, and
 * the monitoring system that watches `var/log` or runs the console command.
 *
 * A channel that fails is logged and stepped over. The point of having four is that the warning
 * survives one of them being broken — and on a shop whose mail is down, one of them is broken by
 * definition.
 */
class Notifier
{
    public function __construct(
        private readonly Config $config,
        private readonly Wording $wording,
        private readonly EmailChannel $email,
        private readonly WebhookChannel $webhook,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> the channels that accepted the warning */
    public function dispatch(Alert $alert, HealthState $state, MailSettings $settings, int $storeId): array
    {
        if (!$alert->shouldSpeak()) {
            return [];
        }

        $this->log($alert, $state, $settings, $storeId);
        $delivered = ['log'];

        if ($this->config->warnsByEmail($storeId) && $this->email->send($alert, $state, $settings, $storeId)) {
            $delivered[] = 'email';
        }
        if ($this->config->warnsByWebhook($storeId) && $this->webhook->send($alert, $state, $settings, $storeId)) {
            $delivered[] = 'webhook';
        }
        if ($this->config->warnsInPanel($storeId)) {
            // Nothing to send: the banner is drawn from the health record, which is already
            // written by the time we get here. Named anyway, so the panel can say where the
            // warning went.
            $delivered[] = 'panel';
        }

        return $delivered;
    }

    /**
     * The line in `var/log/system.log`, which is the channel an outside monitor watches.
     *
     * At `critical` for a problem, because that is the level log shippers alert on, and at
     * `info` for a recovery, because nobody should be paged to be told something is fine.
     */
    private function log(Alert $alert, HealthState $state, MailSettings $settings, int $storeId): void
    {
        $context = Redaction::applyToArray([
            'store_id' => $storeId,
            'status' => $state->status,
            'cause' => $state->cause,
            'stage' => $state->stage,
            'endpoint' => $settings->endpoint(),
            'provider' => $settings->providerId,
            'reply' => $state->reply,
            'reminder' => $alert->isReminder,
        ], array_values(array_filter([$settings->password])));

        if ($alert->isProblem()) {
            $this->logger->critical((string) $this->wording->headline($state, $settings), $context);

            return;
        }

        $this->logger->info((string) $this->wording->recovery($settings), $context);
    }
}
