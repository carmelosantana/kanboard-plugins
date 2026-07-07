<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Plugin;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class CalendarDecoratorTest extends Base
{
    private function guardContainer()
    {
        if (! isset($this->container['cli'])) {
            $this->container['cli'] = new \Symfony\Component\Console\Application();
        }
        if (! isset($this->container['eventManager'])) {
            $this->container['eventManager'] = new \Kanboard\Core\Event\EventManager();
        }
    }

    public function testDecoratorAddsBadgeForRecentlyMovedTask()
    {
        $this->guardContainer();

        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $task = $tc->create(['project_id' => $pid, 'title' => 't']);

        // Mark the task moved today, via a standalone model instance so we don't
        // resolve (and thereby freeze) the container's own schedulerConfigModel
        // service before Plugin::initialize() gets to register it.
        (new SchedulerConfigModel($this->container))->setTaskLastMove($task, date('Y-m-d'));

        // Initialize the plugin so it appends the decorator.
        (new Plugin($this->container))->initialize();
        $decorators = $this->container['calendarEventDecorators'];
        $this->assertNotEmpty($decorators);

        $event = ['id' => $task, 'extendedProps' => ['badges' => []]];
        $row = ['project_id' => $pid];
        foreach ($decorators as $d) {
            $event = call_user_func($d, $event, $row);
        }

        $texts = array_column($event['extendedProps']['badges'], 'text');
        $this->assertContains("\u{23F0}", $texts);
    }

    public function testDecoratorSkipsUnmovedTask()
    {
        $this->guardContainer();

        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $task = $tc->create(['project_id' => $pid, 'title' => 't']); // never moved

        (new Plugin($this->container))->initialize();
        $event = ['id' => $task, 'extendedProps' => ['badges' => []]];
        foreach ($this->container['calendarEventDecorators'] as $d) {
            $event = call_user_func($d, $event, ['project_id' => $pid]);
        }
        $this->assertEmpty($event['extendedProps']['badges']);
    }
}
