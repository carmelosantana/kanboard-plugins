<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class SchedulerControllerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
        $this->container['schedulerConfigModel'] = function ($c) { return new SchedulerConfigModel($c); };
        $this->container['schedulerLogModel'] = function ($c) { return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel($c); };
        $this->container['schedulerRunner'] = function ($c) { return new SchedulerRunner($c); };
    }

    public function testManualDryRunProducesCountWithoutWriting()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);
        $tc->create(['project_id' => $pid, 'title' => 'o', 'date_due' => (int) (new \DateTime('today'))->getTimestamp() - 5 * 86400]);

        $result = $this->container['schedulerRunner']->run(['dry_run' => true, 'trigger' => 'manual']);
        $this->assertSame(1, $result['total_moved']);
        $this->assertCount(0, $this->container['db']->table('scheduler_moves')->findAll());
    }
}
