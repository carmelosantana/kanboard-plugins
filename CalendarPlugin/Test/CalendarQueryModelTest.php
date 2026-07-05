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

    // D4: timed event end == start + 2h, ISO-8601 strings
    public function testTimedEventEndIsTwoHoursAfterStart()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);
        $events = $model->getEvents(1, array(), $start, $end);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $e1 = $byId[$t1];
        // start and end are valid ISO-8601 strings
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $e1['start']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $e1['end']);
        // end == start + 2 hours
        $startTs = strtotime($e1['start']);
        $endTs   = strtotime($e1['end']);
        $this->assertEquals(7200, $endTs - $startTs, 'end must be exactly 2 hours after start');
    }

    // D4: midnight due + estimate defaults start to 09:00
    public function testMidnightDueWithEstimateDefaultsStartToNineAm()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'Midnight Test'));
        $dueMidnight = mktime(0, 0, 0, (int) date('n'), 20);
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'Midnight task', 'date_due' => $dueMidnight, 'time_estimated' => 1));

        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);
        $events = $model->getEvents(1, array(), $start, $end);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $this->assertArrayHasKey($tid, $byId, 'midnight task must appear in events');
        $startHour = (int) date('H', strtotime($byId[$tid]['start']));
        $this->assertEquals(9, $startHour, 'midnight due with estimate must default start to 09:00');
    }

    // D4: overdue open task has extendedProps.overdue === true; future task has overdue === false
    public function testOverdueFlagIsSetCorrectly()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'Overdue Test'));

        // Past date well within the query window (use a fixed past timestamp so it's always in the past)
        $pastDue   = mktime(12, 0, 0, 1, 15, 2025); // Jan 15 2025 — always in the past
        $futureDue = mktime(12, 0, 0, (int) date('n'), 15); // this month's 15th (future or today)

        $tOverdue = $taskCreation->create(array('project_id' => $pid, 'title' => 'Overdue task', 'date_due' => $pastDue));
        $tFuture  = $taskCreation->create(array('project_id' => $pid, 'title' => 'Future task',  'date_due' => $futureDue));

        $model = new CalendarQueryModel($this->container);
        // Use a wide window that includes both dates
        $winStart = mktime(0, 0, 0, 1, 1, 2025);
        $winEnd   = mktime(0, 0, 0, (int) date('n') + 1, 1);

        $events = $model->getEvents(1, array(), $winStart, $winEnd);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $this->assertArrayHasKey($tOverdue, $byId, 'overdue task must appear in events');
        $this->assertArrayHasKey($tFuture, $byId, 'future task must appear in events');
        $this->assertTrue($byId[$tOverdue]['extendedProps']['overdue'], 'overdue open task must have overdue===true');
        $this->assertFalse($byId[$tFuture]['extendedProps']['overdue'], 'future task must have overdue===false');
    }

    // R2: Non-admin member sees only their own project's tasks
    public function testNonAdminMemberSeesOnlyMemberProjects()
    {
        $projectModel    = new ProjectModel($this->container);
        $taskCreation    = new TaskCreationModel($this->container);
        $userModel       = new \Kanboard\Model\UserModel($this->container);
        $projectUserRole = new \Kanboard\Model\ProjectUserRoleModel($this->container);

        $uid = $userModel->create(array('username' => 'cal_member', 'password' => 'test1234', 'role' => \Kanboard\Core\Security\Role::APP_USER));
        $this->assertNotFalse($uid);
        $mine    = $projectModel->create(array('name' => 'MemberProj'));
        $foreign = $projectModel->create(array('name' => 'ForeignProj'));
        // add the non-admin as a member of $mine only
        $projectUserRole->addUser($mine, $uid, \Kanboard\Core\Security\Role::PROJECT_MEMBER);

        $due = mktime(12, 0, 0, (int) date('n'), 16);
        $tMine    = $taskCreation->create(array('project_id' => $mine,    'title' => 'mine',    'date_due' => $due));
        $tForeign = $taskCreation->create(array('project_id' => $foreign, 'title' => 'foreign', 'date_due' => $due));

        $model = new CalendarQueryModel($this->container);
        $ids = array_column($model->getEvents($uid, array(),
            mktime(0, 0, 0, (int) date('n'), 1), mktime(0, 0, 0, (int) date('n') + 1, 1)), 'id');
        $this->assertContains($tMine, $ids, 'member must see their own project task');
        $this->assertNotContains($tForeign, $ids, 'member must NOT see a project they are not on');
    }
}
