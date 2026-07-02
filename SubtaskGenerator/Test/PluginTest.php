<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SubtaskGenerator\Plugin;
use KanboardTests\units\Base;

/**
 * Smoke tests for SubtaskGenerator Plugin.
 *
 * Run from the Kanboard root via the plugin test harness:
 *   ./testing/run-plugin-tests.sh SubtaskGenerator
 */
class PluginTest extends Base
{
    public function testPluginMetadata(): void
    {
        $plugin = new Plugin($this->container);

        $this->assertSame('SubtaskGenerator', $plugin->getPluginName());
        $this->assertSame('0.1.0', $plugin->getPluginVersion());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertNotEmpty($plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginHomepage());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testPhpGatePassesOn84(): void
    {
        $plugin = new Plugin($this->container);

        // Simulate PHP 8.4.0 — gate should pass.
        $this->assertTrue($plugin->isPhpCompatible(80400));

        // Simulate PHP 8.4.19 (the container's actual version) — gate should pass.
        $this->assertTrue($plugin->isPhpCompatible(80419));

        // Simulate PHP 9.0 — gate should pass.
        $this->assertTrue($plugin->isPhpCompatible(90000));
    }

    public function testPhpGateFailsBelow84(): void
    {
        $plugin = new Plugin($this->container);

        // Simulate PHP 8.3.x — gate should fail.
        $this->assertFalse($plugin->isPhpCompatible(80399));

        // Simulate PHP 8.3.0.
        $this->assertFalse($plugin->isPhpCompatible(80300));

        // Simulate PHP 7.4 — gate should fail.
        $this->assertFalse($plugin->isPhpCompatible(70400));
    }

    public function testVendorAutoloadExists(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $this->assertFileExists(
            $autoload,
            'vendor/autoload.php must exist — run composer install inside SubtaskGenerator/'
        );
    }

    public function testProviderClassesResolve(): void
    {
        // Ensure vendor/autoload.php is loaded (plugin initialize() does this at runtime;
        // in unit tests we load it explicitly so the classes are available).
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $this->assertTrue(
            class_exists('CarmeloSantana\\PHPAgents\\Provider\\AnthropicProvider'),
            'AnthropicProvider must resolve after loading plugin vendor/autoload.php'
        );
        $this->assertTrue(
            class_exists('CarmeloSantana\\PHPAgents\\Provider\\OpenAIResponsesProvider'),
            'OpenAIResponsesProvider must resolve'
        );
        $this->assertTrue(
            class_exists('CarmeloSantana\\PHPAgents\\Provider\\OpenAICompatibleProvider'),
            'OpenAICompatibleProvider must resolve'
        );
        $this->assertTrue(
            class_exists('CarmeloSantana\\PHPAgents\\Provider\\XAIProvider'),
            'XAIProvider must resolve'
        );
    }
}
