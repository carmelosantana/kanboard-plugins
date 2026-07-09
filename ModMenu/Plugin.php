<?php

namespace Kanboard\Plugin\ModMenu;

use Kanboard\Core\Plugin\Base;

/**
 * ModMenu — a standalone Kanboard plugin manager.
 *
 * Browse/install from directory sources, upload a zip, enable/disable via
 * folder move, detect updates, and uninstall — all admin-only.
 *
 * @author  Carmelo Santana
 * @license MIT
 */
class Plugin extends Base
{
    public function initialize()
    {
        $this->hook->on('template:config:sidebar', ['template' => 'ModMenu:config/sidebar']);

        $this->hook->on('template:layout:css', ['template' => 'plugins/ModMenu/Assets/css/modmenu.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/ModMenu/Assets/js/modmenu.js']);

        $this->route->addRoute('config/modmenu', 'ModMenu:ModMenuController', 'show');
        $this->route->addRoute('config/modmenu/directory', 'ModMenu:ModMenuController', 'directory');
        $this->route->addRoute('config/modmenu/sources', 'ModMenu:ModMenuController', 'sources');
        $this->route->addRoute('config/modmenu/source/add', 'ModMenu:ModMenuController', 'addSource');
        $this->route->addRoute('config/modmenu/source/remove', 'ModMenu:ModMenuController', 'removeSource');
        $this->route->addRoute('config/modmenu/plugin/confirm', 'ModMenu:ModMenuController', 'confirm');
        $this->route->addRoute('config/modmenu/plugin/resolve', 'ModMenu:ModMenuController', 'resolve');
        $this->route->addRoute('config/modmenu/plugin/enable', 'ModMenu:ModMenuController', 'enable');
        $this->route->addRoute('config/modmenu/plugin/disable', 'ModMenu:ModMenuController', 'disable');
        $this->route->addRoute('config/modmenu/plugin/uninstall', 'ModMenu:ModMenuController', 'uninstall');
        $this->route->addRoute('config/modmenu/plugin/install', 'ModMenu:ModMenuController', 'install');
        $this->route->addRoute('config/modmenu/plugin/update', 'ModMenu:ModMenuController', 'update');
        $this->route->addRoute('config/modmenu/upload', 'ModMenu:UploadController', 'upload');
    }

    public function getPluginName(): string
    {
        return 'ModMenu';
    }

    public function getPluginDescription(): string
    {
        return 'A standalone plugin manager: browse, install, upload, enable/disable, update, and uninstall Kanboard plugins.';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.1';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/ModMenu';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }
}
