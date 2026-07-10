<?php

namespace Kanboard\Plugin\SubtaskGenerator\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiGate — single source of truth for "is AI subtask generation available?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present AND ProviderRegistry::isReady().
 * Consulted identically by Plugin::initialize() (sidebar link) and
 * GeneratorController::isAiEnabled() (route guards) so they never diverge.
 *
 * class_exists(ProviderRegistry) is safe at initialize() time — it loads the
 * class file but not php-agents (method type-hints resolve lazily). isReady()
 * touches no php-agents class.
 */
class AiGate
{
    /**
     * @param \Pimple\Container $container
     * @param int|null  $phpVersionId     PHP_VERSION_ID override (tests).
     * @param bool|null $connectorPresent AiConnector-present override (tests).
     */
    public static function isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null): bool
    {
        $versionId = $phpVersionId ?? PHP_VERSION_ID;
        if ($versionId < 80400) {
            return false;
        }

        $present = $connectorPresent
            ?? class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class);
        if (! $present) {
            return false;
        }

        return (new ProviderRegistry($container))->isReady();
    }
}
