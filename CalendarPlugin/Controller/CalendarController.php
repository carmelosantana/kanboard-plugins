<?php

namespace Kanboard\Plugin\CalendarPlugin\Controller;

use Kanboard\Controller\BaseController;

class CalendarController extends BaseController
{
    /**
     * Global calendar page (all of the user's accessible projects).
     */
    public function show()
    {
        $this->response->html($this->helper->layout->app('CalendarPlugin:calendar/index', array(
            'title'          => t('Calendar'),
            'project_id'     => 0,
            'events_url'     => $this->helper->url->to('CalendarController', 'events', array('plugin' => 'CalendarPlugin')),
            'update_url'     => $this->helper->url->to('CalendarController', 'updateDate', array('plugin' => 'CalendarPlugin')),
            'unscheduled_url'=> $this->helper->url->to('CalendarController', 'unscheduled', array('plugin' => 'CalendarPlugin')),
            'csrf'           => $this->token->getReusableCSRFToken(),
        )));
    }

    /**
     * JSON feed of FullCalendar events for the visible range + filters.
     * (calendar.getEvents)
     */
    public function events()
    {
        $userId = $this->userSession->getId();
        $start  = $this->parseDate($this->request->getStringParam('start'), strtotime('-1 month'));
        $end    = $this->parseDate($this->request->getStringParam('end'), strtotime('+2 month'));

        $filters = array(
            'project_ids'    => $this->intList($this->request->getStringParam('project_ids')),
            'assignee_id'    => $this->resolveAssignee($this->request->getStringParam('assignee_id'), $userId),
            'category_id'    => (int) $this->request->getIntegerParam('category_id'),
            'column_id'      => (int) $this->request->getIntegerParam('column_id'),
            'hide_completed' => $this->request->getStringParam('hide_completed') === '1',
        );

        $events = $this->container['calendarQueryModel']->getEvents($userId, $filters, $start, $end);
        $this->response->json($events);
    }

    private function parseDate($value, $default)
    {
        if (empty($value)) { return $default; }
        $ts = strtotime($value);
        return $ts !== false ? $ts : $default;
    }

    private function intList($value)
    {
        if (empty($value)) { return array(); }
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    /** '-1' (or 'me') means the current user; '' means no assignee filter. */
    private function resolveAssignee($value, $userId)
    {
        if ($value === 'me' || $value === '-1') { return (int) $userId; }
        return (int) $value;
    }
}
