<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Trigger\WebCronTrigger;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use KanboardTests\units\Base;

class WebCronTriggerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['schedulerConfigModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel($c);
        };
        $this->container['schedulerRunner'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner($c);
        };
    }

    public function testDoesNotFireWhenMasterOff()
    {
        $trigger = new WebCronTrigger($this->container);
        $this->assertFalse($trigger->maybeRun());
    }

    public function testDoesNotFireWhenAlreadyRanToday()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::TARGET_HOUR => '0', // any hour qualifies
        ]);
        $cfg->setLastRun(date('Y-m-d')); // already ran today
        $trigger = new WebCronTrigger($this->container);
        $this->assertFalse($trigger->maybeRun());
    }

    public function testFiresAndStampsLastRunWhenDue()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::TARGET_HOUR => '0',
        ]);
        $cfg->setLastRun(date('Y-m-d', time() - 86400)); // last ran yesterday

        $trigger = new WebCronTrigger($this->container);
        $this->assertTrue($trigger->maybeRun());
        $this->assertSame(date('Y-m-d'), $cfg->getLastRun()); // stamped today

        // Second call same day is a no-op.
        $this->assertFalse($trigger->maybeRun());
    }
}
