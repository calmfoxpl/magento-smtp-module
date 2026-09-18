<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Health;

use Calmfox\Smtp\Core\Alert\Alert;
use Calmfox\Smtp\Core\Alert\AlertPolicy;
use Calmfox\Smtp\Core\Health\HealthEvaluator;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Model\Alert\Notifier;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Probe\SmtpProbe;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The health check as the rest of the module sees it: check, remember, and warn once.
 *
 * The orchestration is all here and the judgement is all in the core, which is why this class
 * has no `if` worth arguing about. Three things are worth knowing about it:
 *
 *  - A verdict is reused for as long as the settings say, so that a dashboard, a cron run and a
 *    console command in the same minute produce one connection, not three. `force` is for the
 *    button an administrator presses, which must always mean now.
 *  - Several store views usually share one provider. They are checked once per distinct set of
 *    settings and the verdict is written for each, because dialling the same server four times
 *    to learn the same thing is how a module ends up rate limited by its own provider.
 *  - Warning is part of checking, not a separate job somebody has to remember to run.
 */
class Monitor
{
    public function __construct(
        private readonly Config $config,
        private readonly SmtpProbe $probe,
        private readonly HealthStore $store,
        private readonly Notifier $notifier,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function stateFor(int $storeId): HealthState
    {
        return $this->store->load($storeId);
    }

    /** One check of one store view's settings. */
    public function check(int $storeId, bool $force = false): HealthState
    {
        $resolved = $this->config->resolve($storeId);
        $settings = $resolved->settings;
        $state = $this->store->load($storeId);

        if (!$settings->enabled || !$this->config->healthCheckEnabled($storeId)) {
            return $state;
        }
        if (!$force && $this->isFresh($state, $settings)) {
            return $state;
        }

        $evaluator = new HealthEvaluator($this->config->thresholds($storeId));
        $now = time();

        $state = $resolved->isUsable()
            ? $evaluator->afterProbe($state, $this->probe->run($settings, $storeId), $settings, $now)
            : $evaluator->afterUnusableSettings($state, $resolved, $now);

        $this->store->save($storeId, $state);

        return $this->announce($storeId, $state, $settings, $now);
    }

    /**
     * Every store view, with the same settings checked once.
     *
     * @return array<int, HealthState>
     */
    public function checkAll(bool $force = false): array
    {
        $states = [];
        $byFingerprint = [];

        foreach ($this->storeIds() as $storeId) {
            $settings = $this->config->resolve($storeId)->settings;
            if (!$settings->enabled || !$this->config->healthCheckEnabled($storeId)) {
                continue;
            }

            $fingerprint = $settings->fingerprint();
            if (isset($byFingerprint[$fingerprint])) {
                // The same server, the same credentials: reuse the verdict and record it here too.
                $states[$storeId] = $byFingerprint[$fingerprint];
                $this->store->save($storeId, $states[$storeId]);
                continue;
            }

            $states[$storeId] = $byFingerprint[$fingerprint] = $this->check($storeId, $force);
        }

        return $states;
    }

    public function recordSendFailure(int $storeId, string $cause, string $message): HealthState
    {
        $settings = $this->config->resolve($storeId)->settings;
        $now = time();

        $state = (new HealthEvaluator($this->config->thresholds($storeId)))
            ->afterSendFailure($this->store->load($storeId), $cause, $message, $now);
        $this->store->save($storeId, $state);

        return $this->announce($storeId, $state, $settings, $now);
    }

    public function recordSendSuccess(int $storeId): HealthState
    {
        $settings = $this->config->resolve($storeId)->settings;
        $previous = $this->store->load($storeId);
        $now = time();

        // A shop that is sending normally would otherwise write this row on every message. The
        // record only needs touching when it has something new to say.
        if ($previous->isBroken() || $previous->sendFailures > 0 || null === $previous->lastSentAt || $now - $previous->lastSentAt > 3600) {
            $state = (new HealthEvaluator($this->config->thresholds($storeId)))->afterSendSuccess($previous, $now);
            $this->store->save($storeId, $state);

            return $this->announce($storeId, $state, $settings, $now);
        }

        return $previous;
    }

    /**
     * Tell whoever asked to be told, once, and write down that we did.
     *
     * The writing down is the part that matters: without it the module either repeats itself
     * every fifteen minutes or forgets to mention that a problem is over.
     */
    private function announce(int $storeId, HealthState $state, MailSettings $settings, int $now): HealthState
    {
        $alert = (new AlertPolicy($this->config->thresholds($storeId)))->decide($state, $now);
        if (!$alert->shouldSpeak()) {
            return $state;
        }

        try {
            $this->notifier->dispatch($alert, $state, $settings, $storeId);
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the warning could not be delivered.', ['exception' => $error]);
        }

        // Marked as told whether or not a channel accepted it: the panel banner is drawn from
        // the state either way, and retrying a dead sendmail every cron run helps nobody.
        $state = Alert::RECOVERY === $alert->kind ? $state->forgotten() : $state->notified($alert->cause, $now);
        $this->store->save($storeId, $state);

        return $state;
    }

    private function isFresh(HealthState $state, MailSettings $settings): bool
    {
        if (!$state->wasEverChecked() || $state->fingerprint !== $settings->fingerprint()) {
            return false;
        }

        return time() - $state->checkedAt < $this->config->checkEverySeconds();
    }

    /** @return list<int> the default configuration, then every active store view */
    private function storeIds(): array
    {
        $ids = [0];
        try {
            foreach ($this->storeManager->getStores() as $store) {
                if ($store->getIsActive()) {
                    $ids[] = (int) $store->getId();
                }
            }
        } catch (\Throwable $error) {
            $this->logger->warning('Calmfox SMTP: the store views could not be listed.', ['exception' => $error]);
        }

        return array_values(array_unique($ids));
    }
}
