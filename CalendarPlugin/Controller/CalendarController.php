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
}
