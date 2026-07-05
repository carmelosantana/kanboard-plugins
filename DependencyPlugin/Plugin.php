<?php

namespace Kanboard\Plugin\DependencyPlugin;

use Kanboard\Core\Plugin\Base;
use Kanboard\Model\TaskLinkModel;
use Kanboard\Plugin\DependencyPlugin\Helper\DependencyHelper;
use Kanboard\Plugin\DependencyPlugin\Model\DependencyModel;
use Kanboard\Plugin\DependencyPlugin\Subscriber\DependencyLinkSubscriber;

class Plugin extends Base
{
    public function initialize()
    {
        $this->container['dependencyModel'] = function ($c) {
            return new DependencyModel($c);
        };

        $container = $this->container;

        $this->dispatcher->addListener(TaskLinkModel::EVENT_CREATE_UPDATE, function ($event) use ($container) {
            (new DependencyLinkSubscriber($container))->onLinkCreateUpdate($event);
        });

        // Template helper: exposes blockedOpenCount($task) to board/task templates.
        $this->helper->register('dependency', DependencyHelper::class);

        // Board card badge: renders a "blocked" indicator before each card's title.
        $this->hook->on('template:board:private:task:before-title', array('template' => 'DependencyPlugin:board/badge'));

        // Task page panel: status-aware "Blocked by" / "Blocks" lists.
        $this->hook->on('template:task:show:before-internal-links', array('template' => 'DependencyPlugin:task/panel'));

        // Inject dependency.css sitewide. Unlike CalendarPlugin's FullCalendar
        // bundle (282KB, route-gated to avoid bloating every page), this
        // stylesheet is ~1KB, so sitewide injection has negligible cost and
        // avoids having to keep a route allowlist in sync with every place
        // the badge might render (board, task modal, search results, etc).
        // This also means calendar pages already receive dependency.css, so
        // the blocked badge below needs no extra asset wiring.
        $this->hook->on('template:layout:css', array('template' => 'plugins/DependencyPlugin/Assets/css/dependency.css'));

        // Calendar integration: consume CalendarPlugin's generic
        // `calendarEventDecorators` extension point (Task 0, CalendarPlugin
        // >= 1.1.0) to push a blocked badge onto FullCalendar events. Append
        // rather than overwrite, since other suite plugins may also register
        // decorators on this same container key. The closure captures $this
        // (the Plugin instance) so `$this->dependencyModel` resolves lazily,
        // whenever CalendarQueryModel::getEvents() actually invokes it.
        $this->container['calendarEventDecorators'] = array_merge(
            isset($this->container['calendarEventDecorators']) ? $this->container['calendarEventDecorators'] : array(),
            array(function (array $event, array $row) {
                $projectId = (int) (isset($row['project_id']) ? $row['project_id'] : 0);
                $map = $this->dependencyModel->getProjectBlockedMap($projectId);
                if (! empty($map[(int) $event['id']]['open_blockers'])) {
                    $event['extendedProps']['badges'][] = array('text' => "\u{1F512}", 'cls' => 'dep-blk');
                }
                return $event;
            })
        );
    }

    public function getPluginName()        { return 'DependencyPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Task dependencies: blocked/blocker badges on board, calendar, and task pages; cycle guard — built on core task links.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
