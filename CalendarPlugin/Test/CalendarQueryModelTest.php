<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class CalendarQueryModelTest extends Base
{
    private function seed()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'Cal P1'));
        // admin user id is 1 in the test container; make admin a member implicitly (owner)
        $due = mktime(12, 0, 0, (int) date('n'), 15); // 15th of this month, noon
        $t1 = $taskCreation->create(array('project_id' => $pid, 'title' => 'With estimate', 'date_due' => $due, 'time_estimated' => 2));
        $t2 = $taskCreation->create(array('project_id' => $pid, 'title' => 'No estimate', 'date_due' => $due, 'time_estimated' => 0));
        return array($pid, $t1, $t2, $due);
    }

    public function testGetEventsMapsDueDateAndEstimate()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);

        $events = $model->getEvents(1, array(), $start, $end);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $this->assertArrayHasKey($t1, $byId);
        $this->assertArrayHasKey($t2, $byId);
        // estimate>0 => timed, has end, not allDay
        $this->assertFalse($byId[$t1]['allDay']);
        $this->assertNotNull($byId[$t1]['end']);
        $this->assertEqualsWithDelta(2.0, $byId[$t1]['extendedProps']['estimate'], 0.001);
        // estimate==0 => allDay
        $this->assertTrue($byId[$t2]['allDay']);
        $this->assertNull($byId[$t2]['end']);
    }

    public function testGetEventsIsWindowedByDateRange()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        // A window in a different month must exclude both tasks.
        $start = mktime(0, 0, 0, (int) date('n') + 2, 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 3, 1);
        $events = $model->getEvents(1, array(), $start, $end);
        $this->assertCount(0, $events);
    }

    public function testAccessibleProjectIdsExcludesForeignProjects()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $ids = $model->accessibleProjectIds(1);
        $this->assertContains($pid, $ids);
    }

    public function testEmptyAccessibleProjectsYieldsNoEvents()
    {
        // user id 999 has no projects -> in([]) must NOT leak the whole table
        $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);
        $events = $model->getEvents(999, array(), $start, $end);
        $this->assertCount(0, $events);
    }
}
