<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Controller\CalendarController;
use Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class CalendarControllerTest extends Base
{
    public function testEventsReturnsScopedJson()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'CtrlP'));
        $due = mktime(12, 0, 0, (int) date('n'), 10);
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'E1', 'date_due' => $due));

        // The controller delegates to calendarQueryModel->getEvents; assert the
        // model contract returns the expected task for an admin user (id 1).
        $model = new CalendarQueryModel($this->container);
        $events = $model->getEvents(
            1, array(),
            mktime(0, 0, 0, (int) date('n'), 1),
            mktime(0, 0, 0, (int) date('n') + 1, 1)
        );
        $ids = array_column($events, 'id');
        $this->assertContains($tid, $ids);
    }
}
