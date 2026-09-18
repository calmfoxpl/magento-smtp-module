<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Console\Command;

use Calmfox\Smtp\Model\Mail\TestSender;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Sends one real message, for when the question is delivery rather than connection. */
class TestCommand extends Command
{
    public function __construct(
        private readonly TestSender $sender,
        private readonly State $state,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('calmfox:smtp:test')
            ->setDescription('Sends a test message through the configured server.')
            ->addArgument('recipient', InputArgument::REQUIRED, 'Where to send it')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store view id; the default configuration if omitted', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set
        }

        $recipient = (string) $input->getArgument('recipient');

        try {
            $milliseconds = $this->sender->send($recipient, (int) $input->getOption('store'));
        } catch (\Throwable $failure) {
            $output->writeln('<error>' . $failure->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>' . __('Accepted by the server for %1 in %2 ms. Whether it arrives is a separate question, and the answer is in that mailbox.', $recipient, $milliseconds) . '</info>');

        return Command::SUCCESS;
    }
}
