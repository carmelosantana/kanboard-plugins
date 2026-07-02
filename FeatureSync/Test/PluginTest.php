<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\FeatureSync\Plugin;
use Kanboard\Plugin\FeatureSync\Model\FeatureSyncModel;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameIsNotEmpty()
    {
        $plugin = new Plugin($this->container);
        $this->assertNotEmpty($plugin->getPluginName());
    }

    public function testPluginVersionIsNotEmpty()
    {
        $plugin = new Plugin($this->container);
        $this->assertNotEmpty($plugin->getPluginVersion());
    }

    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('FeatureSync', $plugin->getPluginName());
    }

    public function testPluginVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('0.1.0', $plugin->getPluginVersion());
    }

    public function testCompatibleVersion()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    // ── FeatureSyncModel: copier map ────────────────────────────────────────

    /**
     * Every supported feature must have an entry in the copier map.
     */
    public function testCopierMapHasAllSupportedFeatures()
    {
        $model = new FeatureSyncModel($this->container);
        $map   = $model->getCopierMap();

        $expected = array(
            FeatureSyncModel::FEATURE_ACTIONS,
            FeatureSyncModel::FEATURE_TAGS,
            FeatureSyncModel::FEATURE_COLUMNS,
            FeatureSyncModel::FEATURE_CATEGORIES,
            FeatureSyncModel::FEATURE_SWIMLANES,
        );

        foreach ($expected as $feature) {
            $this->assertArrayHasKey(
                $feature,
                $map,
                "getCopierMap() must contain an entry for feature '{$feature}'"
            );
        }
    }

    /**
     * Each copier map entry must name a real core class and a real method on it.
     *
     * This is the guard that ensures our documented core API references stay valid
     * as the kanboard core evolves.
     *
     * Verified against kanboard-1.2.47:
     *   ActionModel::duplicate         app/Model/ActionModel.php:171
     *   TagDuplicationModel::duplicate app/Model/TagDuplicationModel.php:23
     *   BoardModel::duplicate          app/Model/BoardModel.php:87
     *   CategoryModel::duplicate       app/Model/CategoryModel.php:209
     *   SwimlaneModel::duplicate       app/Model/SwimlaneModel.php:437
     */
    public function testCopierMapReferencesRealCoreClassesAndMethods()
    {
        $model = new FeatureSyncModel($this->container);
        $map   = $model->getCopierMap();

        foreach ($map as $feature => $entry) {
            $class  = $entry['class'];
            $method = $entry['method'];

            $this->assertTrue(
                class_exists($class),
                "Copier map entry '{$feature}': class '{$class}' does not exist in core"
            );

            $this->assertTrue(
                method_exists($class, $method),
                "Copier map entry '{$feature}': method '{$class}::{$method}()' does not exist in core"
            );
        }
    }

    /**
     * getFeatureList() must return a label for every feature in the copier map
     * (so the UI can render checkboxes for everything the model supports).
     */
    public function testFeatureListMatchesCopierMapKeys()
    {
        $model       = new FeatureSyncModel($this->container);
        $map         = $model->getCopierMap();
        $featureList = $model->getFeatureList();

        foreach (array_keys($map) as $feature) {
            $this->assertArrayHasKey(
                $feature,
                $featureList,
                "getFeatureList() must include a label for feature '{$feature}'"
            );
        }
    }

    /**
     * copyFeature() must throw InvalidArgumentException for unknown features.
     */
    public function testCopyFeatureThrowsOnUnknownFeature()
    {
        $this->expectException(\InvalidArgumentException::class);

        $model = new FeatureSyncModel($this->container);
        $model->copyFeature('nonexistent_feature', 1, 2);
    }

    /**
     * copyFeature() must throw RuntimeException (not-implemented stub) for valid
     * features — confirms the stub is wired and the feature key is recognised.
     */
    public function testCopyFeatureStubThrowsRuntimeExceptionForValidFeature()
    {
        $this->expectException(\RuntimeException::class);

        $model = new FeatureSyncModel($this->container);
        $model->copyFeature(FeatureSyncModel::FEATURE_ACTIONS, 1, 2);
    }
}
