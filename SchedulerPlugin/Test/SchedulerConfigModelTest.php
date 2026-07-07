<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class SchedulerConfigModelTest extends Base
{
    public function testDefaults()
    {
        $m = new SchedulerConfigModel($this->container);
        $this->assertFalse($m->isMasterEnabled());
        $this->assertSame(2, $m->getTargetHour());
        $this->assertSame([1, 2, 3, 4, 5], $m->getWorkingDays());
        $this->assertSame([], $m->getHolidays());
        $this->assertSame(0, $m->getDeclumpThreshold());
        $this->assertTrue($m->respectBlocks());
        $this->assertTrue($m->postToActivity());
        $this->assertSame(3, $m->getBadgeDays());
    }

    public function testRoundTripGlobals()
    {
        $m = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::TARGET_HOUR => '5',
            SchedulerConfigModel::WORKING_DAYS => '1,2,3',
            SchedulerConfigModel::HOLIDAYS => "2026-12-25\n2026-01-01",
            SchedulerConfigModel::DECLUMP => '4',
            SchedulerConfigModel::RESPECT_BLOCKS => '0',
        ]);
        $this->assertTrue($m->isMasterEnabled());
        $this->assertSame(5, $m->getTargetHour());
        $this->assertSame([1, 2, 3], $m->getWorkingDays());
        $this->assertSame(['2026-12-25', '2026-01-01'], $m->getHolidays());
        $this->assertSame(4, $m->getDeclumpThreshold());
        $this->assertFalse($m->respectBlocks());
    }

    public function testProjectEnableToggleAndList()
    {
        $p = new ProjectModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $m = new SchedulerConfigModel($this->container);

        $this->assertFalse($m->isProjectEnabled($pid));
        $this->assertSame([], $m->enabledProjectIds());

        $m->setProjectEnabled($pid, true);
        $this->assertTrue($m->isProjectEnabled($pid));
        $this->assertSame([$pid], $m->enabledProjectIds());

        $m->setProjectEnabled($pid, false);
        $this->assertFalse($m->isProjectEnabled($pid));
        $this->assertSame([], $m->enabledProjectIds());
    }

    public function testRecentlyMovedTaskIdsWindow()
    {
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $recent = $tc->create(['project_id' => $pid, 'title' => 'recent']);
        $old = $tc->create(['project_id' => $pid, 'title' => 'old']);

        $m = new SchedulerConfigModel($this->container);
        $m->setTaskLastMove($recent, date('Y-m-d'));                       // today
        $m->setTaskLastMove($old, date('Y-m-d', time() - 10 * 86400));      // 10 days ago (outside default 3-day window)

        $ids = $m->recentlyMovedTaskIds($pid);
        $this->assertContains($recent, $ids);
        $this->assertNotContains($old, $ids);
    }
}
