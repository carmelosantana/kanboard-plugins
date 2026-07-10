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
        $this->assertSame('1.1.0', $plugin->getPluginVersion());
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

    // ── AiGate coverage ─────────────────────────────────────────────────────────
    //
    // AiGate::isReady() is the single source of truth for "is AI subtask
    // generation available?" — PHP >= 8.4 AND AiConnector present AND
    // ProviderRegistry::isReady(). Both Plugin::initialize() and
    // GeneratorController::isAiEnabled() delegate to it (see GeneratorTest for
    // controller-level gate-parity coverage).

    public function testAiGateFalseBelowPhp84(): void
    {
        $this->assertFalse(\Kanboard\Plugin\SubtaskGenerator\Model\AiGate::isReady($this->container, 80399, true));
    }

    public function testAiGateFalseWhenConnectorAbsent(): void
    {
        $this->assertFalse(\Kanboard\Plugin\SubtaskGenerator\Model\AiGate::isReady($this->container, 80400, false));
    }

    public function testAiGateFalseWhenNoProfileConfigured(): void
    {
        // PHP ok, connector present, but no AiConnector profile stored → registry isReady() is false.
        $this->assertFalse(\Kanboard\Plugin\SubtaskGenerator\Model\AiGate::isReady($this->container, 80400, true));
    }

    public function testAiGateTrueWhenProfileConfigured(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key-for-gate-test',
        ]);

        $this->assertTrue(\Kanboard\Plugin\SubtaskGenerator\Model\AiGate::isReady($this->container, 80400, true));
    }

    public function testPluginInitializeUsesAiGate(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key-for-gate-test',
        ]);

        $plugin = new Plugin($this->container);
        $plugin->initialize();

        $this->assertTrue(
            $plugin->isAiEnabled(),
            'isAiEnabled() must return true on PHP 8.4 when AiConnector is present and a provider profile is configured'
        );
    }
}
