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
        $options = array(
            'dry_run' => (bool) $input->getOption('dry-run'),
            'trigger' => 'cli',
        );
        if ($input->getOption('project')) {
            $options['project_id'] = (int) $input->getOption('project');
        }

        $result = $this->schedulerRunner->run($options);

        $mode = $result['dry_run'] ? 'DRY-RUN' : 'applied';
        $output->writeln(sprintf('<info>Scheduler %s:</info> %d task(s) across %d project(s).', $mode, $result['total_moved'], count($result['projects'])));

        foreach ($result['projects'] as $p) {
            $output->writeln(sprintf('  project %d: %d move(s)', $p['project_id'], count($p['moves'])));
        }

        return 0;
    }
}
