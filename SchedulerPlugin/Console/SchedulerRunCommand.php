<?php

namespace Kanboard\Plugin\SchedulerPlugin\Console;

use Kanboard\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SchedulerRunCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('scheduler:run')
            ->setDescription('Reschedule overdue tasks in opt-in projects')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview moves without writing')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Limit to one project id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>scheduler:run</info> registered.');
        return 0;
    }
}
