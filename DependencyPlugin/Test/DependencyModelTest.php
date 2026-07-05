<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\DependencyPlugin\Model\DependencyModel;
use KanboardTests\units\Base;

class DependencyModelTest extends Base
{
    public function testBlockedMapCountsOpenBlockersOnly()
    {
        $p = new \Kanboard\Model\ProjectModel($this->container);
        $tc = new \Kanboard\Model\TaskCreationModel($this->container);
        $tl = new \Kanboard\Model\TaskLinkModel($this->container);
        $ts = new \Kanboard\Model\TaskStatusModel($this->container);
        $linkModel = new \Kanboard\Model\LinkModel($this->container);

        $blockedById = $linkModel->getByLabel('is blocked by');
        $this->assertNotEmpty($blockedById);
        $blockedById = $blockedById['id'];

        $pid = $p->create(array('name' => 'Blk'));
        $a = $tc->create(array('project_id' => $pid, 'title' => 'A'));
        $b = $tc->create(array('project_id' => $pid, 'title' => 'B (blocker)'));
        $tl->create($a, $b, $blockedById); // "A is blocked by B"

        $model = new DependencyModel($this->container);
        $map = $model->getProjectBlockedMap($pid);
        $this->assertSame(1, $map[$a]['open_blockers']);   // B is open
        $this->assertSame(1, $map[$b]['blocks']);          // B blocks A

        $ts->close($b);                                    // complete the blocker
        $model2 = new DependencyModel($this->container);
        $map2 = $model2->getProjectBlockedMap($pid);
        $this->assertTrue(empty($map2[$a]['open_blockers']) || $map2[$a]['open_blockers'] === 0);
    }

    public function testBlockedMapMemoizedAndEmptyProject()
    {
        $p = new \Kanboard\Model\ProjectModel($this->container);
        $pid = $p->create(array('name' => 'Empty'));

        $model = new DependencyModel($this->container);
        $map1 = $model->getProjectBlockedMap($pid);
        $this->assertSame(array(), $map1);

        // Second call for the same project must return the memoized (identical) result
        // without re-querying. We can't easily count queries from here, so we assert
        // idempotent output and reach into the private cache to confirm it was populated.
        $map2 = $model->getProjectBlockedMap($pid);
        $this->assertSame($map1, $map2);

        $reflection = new \ReflectionClass($model);
        $prop = $reflection->getProperty('cache');
        $prop->setAccessible(true);
        $cache = $prop->getValue($model);
        $this->assertArrayHasKey($pid, $cache);
        $this->assertSame(array(), $cache[$pid]);
    }
}
