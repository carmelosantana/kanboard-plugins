<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskFinderModel;
use KanboardTests\units\Base;

class SchedulerRunnerTest extends Base
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
    }

    private function midnight($daysAgo)
    {
        $t = time() - $daysAgo * 86400;
        return (int) (new \DateTime('@'.$t))->setTime(0, 0, 0)->getTimestamp();
    }

    public function testMasterOffDoesNothing()
    {
        $runner = new SchedulerRunner($this->container);
        $result = $runner->run();
        $this->assertSame(0, $result['total_moved']);
        $this->assertNull($result['run_id']);
    }

    public function testRollsOverdueTaskInEnabledProject()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7', // every day is a working day → deterministic
        ]);

        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);

        $task = $tc->create(['project_id' => $pid, 'title' => 'overdue', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run(['trigger' => 'cli']);

        $this->assertSame(1, $result['total_moved']);

        $tf = new TaskFinderModel($this->container);
        $reloaded = $tf->getById($task);
        $this->assertSame(date('Y-m-d'), date('Y-m-d', (int) $reloaded['date_due'])); // now due today
        $this->assertSame(date('Y-m-d'), $cfg->recentlyMovedTaskIds($pid) ? date('Y-m-d', time()) : ''); // marker set (window contains it)
        $this->assertContains($task, $cfg->recentlyMovedTaskIds($pid));
    }

    public function testDisabledProjectUntouched()
    {
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']); // NOT enabled
        $task = $tc->create(['project_id' => $pid, 'title' => 'overdue', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run();
        $this->assertSame(0, $result['total_moved']);

        $tf = new TaskFinderModel($this->container);
        $this->assertSame($this->midnight(5), (int) $tf->getById($task)['date_due']); // unchanged
    }

    public function testDryRunWritesNothing()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);
        $task = $tc->create(['project_id' => $pid, 'title' => 'overdue', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run(['dry_run' => true]);

        $this->assertSame(1, $result['total_moved']);      // projected
        $this->assertNull($result['run_id']);              // nothing persisted
        $tf = new TaskFinderModel($this->container);
        $this->assertSame($this->midnight(5), (int) $tf->getById($task)['date_due']); // task unchanged
        $this->assertNotContains($task, $cfg->recentlyMovedTaskIds($pid));            // no marker
        $moves = $this->container['db']->table('scheduler_moves')->findAll();
        $this->assertCount(0, $moves);
    }

    public function testProjectScopeLimitsToOne()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $a = $p->create(['name' => 'A']);
        $b = $p->create(['name' => 'B']);
        $cfg->setProjectEnabled($a, true);
        $cfg->setProjectEnabled($b, true);
        $tc->create(['project_id' => $a, 'title' => 'oa', 'date_due' => $this->midnight(5)]);
        $tc->create(['project_id' => $b, 'title' => 'ob', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run(['project_id' => $a]);
        $this->assertSame(1, $result['total_moved']);
        $this->assertSame($a, $result['projects'][0]['project_id']);
    }

    public function testCreatesActivitySummaryAnchoredToMovedTask()
    {
        // Ensure a valid creator user exists and own the task via creator_id.
        $userModel = new \Kanboard\Model\UserModel($this->container);
        $uid = $userModel->create(array('username' => 'sched_creator', 'password' => 'x'));
        $this->assertGreaterThan(0, $uid);

        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save(array(
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7',
        ));

        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(array('name' => 'P'));
        $cfg->setProjectEnabled($pid, true);
        $task = $tc->create(array('project_id' => $pid, 'title' => 'overdue', 'creator_id' => $uid, 'date_due' => $this->midnight(5)));

        // Sanity: creator persisted.
        $this->assertSame($uid, (int) (new TaskFinderModel($this->container))->getById($task)['creator_id']);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run(array('trigger' => 'cli'));
        $this->assertSame(1, $result['total_moved']);

        $rows = $this->container['db']->table('project_activities')
            ->eq('event_name', 'scheduler.tasks.rescheduled')
            ->eq('project_id', $pid)
            ->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame($task, (int) $rows[0]['task_id']);
        $this->assertSame($uid, (int) $rows[0]['creator_id']);
        $data = json_decode($rows[0]['data'], true);
        $this->assertSame(1, (int) $data['count']);
    }
}
