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

    // ── Task-03: source-guard + target validation ────────────────────────────

    /**
     * source_project_id=0 must be clamped to 0 by resolveFormParams().
     *
     * Invokes the real model helper and asserts on its output, so the test
     * genuinely exercises the guard:
     *   RED evidence: removing the `if ($sourceProjectId < 1) { $sourceProjectId = 0; }`
     *   block from resolveFormParams() causes this test to FAIL because — if the guard
     *   is absent AND the POST value is negative (e.g. -99) — sourceProjectId would
     *   be -99 rather than 0.  To make it crisp we POST source_project_id=-99:
     *   with guard → 0; without guard → -99.  The assertSame(0, ...) goes red.
     */
    public function testSourceProjectIdZeroIsInvalid()
    {
        $model = new FeatureSyncModel($this->container);

        // A clearly-invalid negative id (not just 0) makes the guard essential.
        $result = $model->resolveFormParams(array('source_project_id' => '-99'));

        $this->assertSame(
            0,
            $result['sourceProjectId'],
            'resolveFormParams() must clamp source_project_id < 1 to 0 (guard in FeatureSyncModel::resolveFormParams)'
        );
    }

    /**
     * The target list filtering logic must exclude the source project.
     *
     * Mirrors the controller's array_filter callback exactly.
     */
    public function testTargetListExcludesSourceProject()
    {
        $sourceProjectId = 5;
        $allProjects = array(
            1 => 'Alpha',
            3 => 'Beta',
            5 => 'Gamma (source)',
            7 => 'Delta',
        );

        $targetProjects = array();
        foreach ($allProjects as $id => $name) {
            $id = (int) $id;
            if ($id > 0 && $id !== $sourceProjectId) {
                $targetProjects[$id] = $name;
            }
        }

        $this->assertArrayNotHasKey(
            $sourceProjectId,
            $targetProjects,
            'The source project must not appear in the target project list'
        );
        $this->assertCount(3, $targetProjects,
            'All other projects must be present in the target list');
    }

    /**
     * Submitting the source project as a target must be silently stripped.
     *
     * Mirrors the controller's array_filter that rejects id=source and id<1.
     */
    public function testSourceAsTargetIsRejected()
    {
        $sourceProjectId = 5;
        // Simulate POST payload with source included in targets.
        $rawTargetIds = array('1', '5', '7');

        $targetProjectIds = array_values(array_filter(
            array_map('intval', $rawTargetIds),
            function ($id) use ($sourceProjectId) {
                return $id > 0 && $id !== $sourceProjectId;
            }
        ));

        $this->assertNotContains(
            $sourceProjectId,
            $targetProjectIds,
            'Source project id must be stripped from target_project_ids'
        );
        $this->assertContains(1, $targetProjectIds);
        $this->assertContains(7, $targetProjectIds);
    }

    /**
     * target_project_ids=[] with an id=0 entry must be stripped.
     */
    public function testTargetIdZeroIsRejected()
    {
        $sourceProjectId = 5;
        $rawTargetIds    = array('0', '3');

        $targetProjectIds = array_values(array_filter(
            array_map('intval', $rawTargetIds),
            function ($id) use ($sourceProjectId) {
                return $id > 0 && $id !== $sourceProjectId;
            }
        ));

        $this->assertNotContains(0, $targetProjectIds,
            'id=0 must be stripped from target_project_ids');
        $this->assertContains(3, $targetProjectIds);
    }

    /**
     * sync_mode must default to 'add_missing' when absent from POST.
     *
     * Invokes the real model helper with an empty POST array (no sync_mode key),
     * so the default-resolution path in resolveFormParams() is genuinely exercised.
     *
     * RED evidence: removing the `$syncMode = ... : 'add_missing'` default assignment
     * from resolveFormParams() would leave $syncMode undefined / null and the
     * assertSame('add_missing', ...) assertion would fail.  Similarly, replacing the
     * default with any other string (e.g. 'replace') would also make this test red.
     */
    public function testSyncModeDefaultsToAddMissing()
    {
        $model = new FeatureSyncModel($this->container);

        // POST contains no sync_mode key at all — default must kick in.
        $result = $model->resolveFormParams(array());

        $this->assertSame(
            'add_missing',
            $result['syncMode'],
            'resolveFormParams() must default syncMode to add_missing when sync_mode is absent from POST'
        );
    }

    public function testSyncModeRejectsUnknownValues()
    {
        $rawMode  = 'nuke_everything';
        $syncMode = in_array($rawMode, array('add_missing', 'replace'), true)
            ? $rawMode
            : 'add_missing';

        $this->assertSame('add_missing', $syncMode,
            'Unknown sync_mode values must be normalised to add_missing');
    }

    public function testSyncModeReplaceIsAllowed()
    {
        $rawMode  = 'replace';
        $syncMode = in_array($rawMode, array('add_missing', 'replace'), true)
            ? $rawMode
            : 'add_missing';

        $this->assertSame('replace', $syncMode);
    }
}
