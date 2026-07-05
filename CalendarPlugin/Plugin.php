<?php

namespace Kanboard\Plugin\CalendarPlugin;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Route: global calendar page.
        $this->route->addRoute('calendar', 'CalendarController', 'show', 'CalendarPlugin');

        // Assets. FullCalendar MUST be injected before calendar.js (both are
        // deferred, so document order = execution order).
        $this->hook->on('template:layout:js', ['template' => 'plugins/CalendarPlugin/Assets/vendor/fullcalendar/index.global.min.js']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/CalendarPlugin/Assets/js/calendar.js']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/CalendarPlugin/Assets/css/calendar.css']);
    }

    public function getPluginName()        { return 'CalendarPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Drag-and-drop calendar view: tasks by due date, across all projects or per project.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
