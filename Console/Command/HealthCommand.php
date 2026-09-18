<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Console\Command;

use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Health\Status;
use Calmfox\Smtp\Core\Settings\MailSettings;
use Calmfox\Smtp\Model\Config;
use Calmfox\Smtp\Model\Dns\DomainCheck;
use Calmfox\Smtp\Model\Health\Monitor;
use Calmfox\Smtp\Model\Text\Wording;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The health check for whatever watches the shop from outside.
 *
 * The exit code is the interface: nought for working, one for a problem that may pass by itself,
 * two for a shop that cannot send. That is enough for Zabbix, for a systemd timer, for a line in
 * somebody's own cron, and for a deployment script that would rather not go live with a shop
 * that cannot e-mail anybody.
 *
 * `--json` exists for the same reason and prints the whole verdict, credentials excluded.
 */
class HealthCommand extends Command
{
    private const EXIT_OK = 0;

    private const EXIT_WARNING = 1;

    private const EXIT_BROKEN = 2;

    private const EXIT_NOT_CONFIGURED = 3;

    public function __construct(
        private readonly Monitor $monitor,
        private readonly Config $config,
        private readonly DomainCheck $domains,
        private readonly Wording $wording,
        private readonly State $state,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('calmfox:smtp:health')
            ->setDescription('Checks whether the shop can send e-mail, and says what is wrong if it cannot.')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store view id; the default configuration if omitted', '0')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Check now instead of reusing a recent verdict')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the whole verdict as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->emulateAdminArea();

        $storeId = (int) $input->getOption('store');
        $resolved = $this->config->resolve($storeId);

        if (!$resolved->settings->enabled) {
            $output->writeln('<comment>' . __('Sending through this module is switched off.') . '</comment>');

            return self::EXIT_NOT_CONFIGURED;
        }

        $state = $this->monitor->check($storeId, true === $input->getOption('force'));

        // With --force the domain is asked again too: "now" has to mean now for both halves of
        // the answer. Without it, whatever cron last stored is what gets printed, because a
        // monitoring command must not hang on a nameserver.
        $domain = true === $input->getOption('force')
            ? $this->domains->refresh($storeId)
            : $this->domains->stored($storeId);

        if (true === $input->getOption('json')) {
            $output->writeln((string) json_encode(
                [
                    'store_id' => $storeId,
                    'settings' => $this->describe($resolved->settings),
                    'health' => $state->toArray(),
                    'sender_domain' => $domain?->toArray(),
                ],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ));

            return $this->exitCode($state);
        }

        $output->writeln($this->line($state, $resolved->settings->endpoint()));
        foreach ($this->wording->explain($state, $resolved->settings) as $sentence) {
            if (Status::OK !== $state->status) {
                $output->writeln('  ' . $sentence);
            }
        }
        foreach ($resolved->warnings() as $warning) {
            $output->writeln('  <comment>' . $this->wording->issue($warning) . '</comment>');
        }

        // The sender domain never changes the exit code. The shop can send; whether receivers
        // believe it is a separate question with a separate fix, and a monitor that went red for
        // it would be asking somebody to do the wrong thing at three in the morning.
        if (null !== $domain && [] !== $domain->issues) {
            $output->writeln($this->wording->deliverabilityHeadline($domain->domain));
            foreach ($domain->issues as $issue) {
                $output->writeln('  <comment>' . $this->wording->issue($issue) . '</comment>');
            }
        }

        return $this->exitCode($state);
    }

    private function line(HealthState $state, string $endpoint): string
    {
        return match ($state->status) {
            Status::OK => sprintf('<info>%s</info> (%s, %d ms)', $this->wording->status($state->status), $endpoint, $state->durationMs),
            Status::WARN => sprintf('<comment>%s</comment> — %s', $this->wording->status($state->status), $this->wording->cause($state->cause)),
            Status::FAIL => sprintf('<error>%s</error> — %s', $this->wording->status($state->status), $this->wording->cause($state->cause)),
            default => sprintf('%s', $this->wording->status($state->status)),
        };
    }

    /** @return array<string, mixed> */
    private function describe(MailSettings $settings): array
    {
        $safe = $settings->withoutSecrets();

        return [
            'provider' => $safe->providerId,
            'endpoint' => $safe->endpoint(),
            'encryption' => $safe->encryption,
            'auth' => $safe->authMethod,
            'username' => $safe->username,
        ];
    }

    private function exitCode(HealthState $state): int
    {
        return match ($state->status) {
            Status::OK => self::EXIT_OK,
            Status::WARN => self::EXIT_WARNING,
            Status::FAIL => self::EXIT_BROKEN,
            default => self::EXIT_NOT_CONFIGURED,
        };
    }

    /**
     * Configuration is read per store view, and a console command starts in no area at all.
     * Without this, every scope-aware read would fall back to defaults and the command would
     * check a configuration no storefront is using.
     */
    private function emulateAdminArea(): void
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set, which is fine
        }
    }
}
