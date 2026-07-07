<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use KanboardTests\units\Base;

class SchedulerToggleTest extends Base
{
    public function testToggleFlipsProjectFlag()
    {
        $p = new ProjectModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $m = new SchedulerConfigModel($this->container);

        $enable = ! $m->isProjectEnabled($pid);
        $m->setProjectEnabled($pid, $enable);
        $this->assertTrue($m->isProjectEnabled($pid));

        $enable = ! $m->isProjectEnabled($pid);
        $m->setProjectEnabled($pid, $enable);
        $this->assertFalse($m->isProjectEnabled($pid));
    }
}
