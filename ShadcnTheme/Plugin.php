<?php

namespace Kanboard\Plugin\ShadcnTheme;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;

/**
 * ShadcnTheme Plugin
 *
 * A modern Kanboard theme inspired by shadcn/ui design principles.
 * Features light/dark mode toggle with system preference detection,
 * beautiful typography, refined color palette, and improved UX.
 *
 * @package  Kanboard\Plugin\ShadcnTheme
 * @author   Carmelo Santana <carmelo@carmelosantana.com>
 * @license  MIT License
 * @link     https://github.com/carmelosantana/kanbanboard-shadcn-theme
 */
class Plugin extends Base
{
    /**
     * Initialize the plugin
     *
     * @access public
     */
    public function initialize()
    {
        // Initialize theme preferences
        $this->initializeTheme();
        
        // Hook into CSS and JS assets
        $this->hookAssets();
        
        // Hook into template overrides
        $this->hookTemplates();
        
        // Hook into user preferences
        $this->hookUserPreferences();
        
        // Add custom routes
        $this->addRoutes();
    }

    /**
     * Initialize theme configuration
     *
     * @return void
     */
    private function initializeTheme()
    {
        // Check if user has theme preference stored
        $currentUserId = $this->userSession->getId();
        if ($currentUserId) {
            $themePreference = $this->userMetadataModel->get(
                $currentUserId,
                'shadcn_theme_mode',
                'dark'
            );

            // Set theme mode in session for JavaScript access
            $_SESSION['shadcn_theme_mode'] = $themePreference;
        } else {
            $_SESSION['shadcn_theme_mode'] = 'dark';
        }
    }

    /**
     * Hook CSS and JavaScript assets
     *
     * @return void
     */
    private function hookAssets()
    {
        // Add shadcn theme CSS
        $this->hook->on('template:layout:css', [
            'template' => 'plugins/ShadcnTheme/Assets/css/shadcn-light.css'
        ]);
        
        $this->hook->on('template:layout:css', [
            'template' => 'plugins/ShadcnTheme/Assets/css/shadcn-dark.css'
        ]);
        
        $this->hook->on('template:layout:css', [
            'template' => 'plugins/ShadcnTheme/Assets/css/shadcn-core.css'
        ]);

        // Login card styles (injected on every page; rules are scoped to .form-login)
        $this->hook->on('template:layout:css', [
            'template' => 'plugins/ShadcnTheme/Assets/css/shadcn-login.css'
        ]);

        // Add theme switching JavaScript
        $this->hook->on('template:layout:js', [
            'template' => 'plugins/ShadcnTheme/Assets/js/theme-switcher.js'
        ]);
        
        $this->hook->on('template:layout:js', [
            'template' => 'plugins/ShadcnTheme/Assets/js/shadcn-enhancements.js'
        ]);
    }

    /**
     * Hook template overrides
     *
     * @return void
     */
    private function hookTemplates()
    {
        // Inject synchronous no-FOUC script in <head> before first paint
        // Also injects the custom favicon <link> when shadcn_favicon_path is configured.
        $this->hook->on('template:layout:head', [
            'template' => 'ShadcnTheme:layout/head'
        ]);

        // Add theme toggle to page header dropdown
        $this->hook->on('template:header:dropdown', [
            'template' => 'ShadcnTheme:header/theme_toggle'
        ]);

        // Login card header (logo or product name + subtitle above the form fields)
        $this->hook->on('template:auth:login-form:before', [
            'template' => 'ShadcnTheme:auth/login_header'
        ]);

        // Sidebar link in Admin Settings
        $this->hook->on('template:config:sidebar', [
            'template' => 'ShadcnTheme:config/sidebar'
        ]);

        // Override the header brand fragment to show the uploaded logo when set
        $this->template->setTemplateOverride('header/title', 'ShadcnTheme:header/title');
    }

    /**
     * Hook user preference handling
     *
     * @return void
     */
    private function hookUserPreferences()
    {
        // Add theme preference to user metadata when changed
        $this->hook->on('model:user-metadata:save', function($userId, $key, $value) {
            if ($key === 'shadcn_theme_mode') {
                // Validate theme mode
                if (!in_array($value, ['light', 'dark', 'system'], true)) {
                    $value = 'system';
                }
                return $value;
            }
            return null;
        });
    }

    /**
     * Add custom routes for theme API and settings
     *
     * @return void
     */
    private function addRoutes()
    {
        $this->route->addRoute('theme/set/:mode', 'ShadcnTheme:ThemeController', 'setTheme');
        $this->route->addRoute('theme/get', 'ShadcnTheme:ThemeController', 'getTheme');

        // Admin settings page
        $this->route->addRoute('shadcn-theme/settings', 'ShadcnTheme:SettingsController', 'show');
        $this->route->addRoute('shadcn-theme/upload', 'ShadcnTheme:SettingsController', 'upload');

        // Asset serving route (logo / favicon) — accessible to unauthenticated users
        // so the favicon and logo img show on the login page.
        // The router resolves the controller name to 'SettingsController' (without plugin prefix)
        // via the plugin= query parameter, so the ACL entry uses the bare controller name.
        $this->route->addRoute('shadcn-theme/asset/:slot', 'ShadcnTheme:SettingsController', 'serveAsset');
        $this->applicationAccessMap->add('SettingsController', ['serveAsset'], Role::APP_PUBLIC);
    }

    /**
     * Get plugin name
     *
     * @return string
     */
    public function getPluginName(): string
    {
        return 'ShadcnTheme';
    }

    /**
     * Get plugin description
     *
     * @return string
     */
    public function getPluginDescription(): string
    {
        return 'Modern Kanboard theme inspired by shadcn/ui design principles with light/dark mode toggle and system preference detection.';
    }

    /**
     * Get plugin author
     *
     * @return string
     */
    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function getPluginVersion(): string
    {
        return '1.0.2';
    }

    /**
     * Get compatible Kanboard version
     *
     * @return string
     */
    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }

    /**
     * Get plugin homepage
     *
     * @return string
     */
    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanbanboard-shadcn-theme';
    }

    /**
     * Get plugin license
     *
     * @return string
     */
    public function getPluginLicense(): string
    {
        return 'MIT';
    }
}