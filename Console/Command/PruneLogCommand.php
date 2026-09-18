<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Console\Command;

use Calmfox\Smtp\Model\Log\Pruner;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Empties the log by hand, which is what somebody wants on the day they realise how much
 * customer correspondence is sitting in it.
 */
class PruneLogCommand extends Command
{
    public function __construct(
        private readonly Pruner $pruner,
        private readonly State $state,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('calmfox:smtp:log:prune')
            ->setDescription('Deletes log entries older than the retention, or older than --days.')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Keep this many days instead of what the settings say')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Delete every entry');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set
        }

        $days = true === $input->getOption('all') ? 0 : $input->getOption('days');
        $removed = $this->pruner->prune(null === $days ? null : max(0, (int) $days));

        $output->writeln((string) __('%1 entries deleted.', $removed));

        return Command::SUCCESS;
    }
}
