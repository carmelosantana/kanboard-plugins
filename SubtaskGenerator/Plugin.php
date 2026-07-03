<?php

namespace Kanboard\Plugin\SubtaskGenerator;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    /**
     * Whether AI features are available (PHP >= 8.4 gate).
     */
    private bool $aiEnabled = false;

    public function initialize(): void
    {
        // Load the plugin-local vendor autoload (php-agents + deps).
        // Guard with file_exists so a missing vendor/ produces a clear error rather than a fatal.
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        } else {
            error_log('[SubtaskGenerator] vendor/autoload.php not found — run composer install inside the plugin directory.');
        }

        // PHP 8.4 hard gate.
        // php-agents requires PHP ^8.4; on older hosts we skip AI wiring gracefully.
        $this->aiEnabled = $this->isPhpCompatible();

        if (! $this->aiEnabled) {
            error_log(
                '[SubtaskGenerator] PHP ' . PHP_VERSION . ' detected — php-agents requires PHP >=8.4. ' .
                'AI features are disabled. Upgrade the host PHP to enable them.'
            );
            // Settings page still loads (shows a disabled notice) — fall through to route wiring.
        }

        // ── Sidebar link in Settings nav ─────────────────────────────────────
        $this->hook->on('template:config:sidebar', [
            'template' => 'SubtaskGenerator:config/sidebar',
        ]);

        // ── Admin settings routes ─────────────────────────────────────────────
        $this->route->addRoute(
            'subtask-generator/settings',
            'SubtaskGenerator:SettingsController',
            'show'
        );
        $this->route->addRoute(
            'subtask-generator/save',
            'SubtaskGenerator:SettingsController',
            'save'
        );
        $this->route->addRoute(
            'subtask-generator/test',
            'SubtaskGenerator:SettingsController',
            'testConnection'
        );
    }

    /**
     * Returns true when the current PHP runtime satisfies the hard gate (>= 8.4.0).
     * Isolated into a method so tests can probe it without spawning a child process.
     *
     * @param int|null $versionId PHP_VERSION_ID override for testing (null = use the real constant)
     */
    public function isPhpCompatible(?int $versionId = null): bool
    {
        return ($versionId ?? PHP_VERSION_ID) >= 80400;
    }

    /**
     * Whether AI features are active (gate passed and vendor loaded).
     */
    public function isAiEnabled(): bool
    {
        return $this->aiEnabled;
    }

    // ---------------------------------------------------------------------------
    // Plugin metadata
    // ---------------------------------------------------------------------------

    public function getPluginName(): string
    {
        return 'SubtaskGenerator';
    }

    public function getPluginDescription(): string
    {
        return t('Generate subtasks from a task description using an AI provider (Anthropic, OpenAI, or Grok).');
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '0.1.0';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/vctrs-io/kanboard-subtask-generator';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
