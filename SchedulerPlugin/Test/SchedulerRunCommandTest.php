<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Console\SchedulerRunCommand;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SchedulerRunCommandTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
        $this->container['schedulerConfigModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel($c);
        };
        $this->container['schedulerLogModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel($c);
        };
        $this->container['schedulerRunner'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner($c);
        };
    }

    private function tester()
    {
        $app = new Application();
        $app->add(new SchedulerRunCommand($this->container));
        return new CommandTester($app->find('scheduler:run'));
    }

    public function testReportsNothingWhenMasterOff()
    {
        $tester = $this->tester();
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('0', $tester->getDisplay());
    }

    public function testDryRunReportsProjectedCountWithoutWriting()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);
        $tc->create(['project_id' => $pid, 'title' => 'o', 'date_due' => (int) (new \DateTime('today'))->getTimestamp() - 5 * 86400]);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('1', $tester->getDisplay());
        $this->assertStringContainsString('dry', strtolower($tester->getDisplay()));
        $this->assertCount(0, $this->container['db']->table('scheduler_moves')->findAll());
    }
}
