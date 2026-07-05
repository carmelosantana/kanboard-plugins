<?php

namespace Kanboard\Plugin\DependencyPlugin;

use Kanboard\Core\Plugin\Base;
use Kanboard\Model\TaskLinkModel;
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
    }

    public function getPluginName()        { return 'DependencyPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Task dependencies: blocked/blocker badges on board, calendar, and task pages; cycle guard — built on core task links.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
