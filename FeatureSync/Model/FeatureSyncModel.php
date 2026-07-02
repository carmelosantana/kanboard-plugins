<?php

namespace Kanboard\Plugin\FeatureSync\Model;

use Kanboard\Core\Base;

/**
 * FeatureSyncModel
 *
 * Defines the supported features and documents the exact core model methods that
 * task-05 will call to copy each feature from a source project to a target project.
 *
 * Core duplication methods are verified against:
 *   kanboard-1.2.47/app/Model/ProjectDuplicationModel.php  — orchestrator
 *
 * Per-feature copier method references (verified file:line in kanboard 1.2.47):
 *
 *   actions    → ActionModel::duplicate($src_project_id, $dst_project_id)
 *                  app/Model/ActionModel.php:171
 *                  (internally calls ActionParameterModel::duplicateParameters)
 *
 *   tags       → TagDuplicationModel::duplicate($src_project_id, $dst_project_id)
 *                  app/Model/TagDuplicationModel.php:23
 *                  (internally calls TagModel::getAllByProject + TagModel::create)
 *
 *   columns    → BoardModel::duplicate($project_from, $project_to)
 *                  app/Model/BoardModel.php:87
 *                  NOTE: parameter names differ (project_from / project_to) but
 *                  semantics are identical: source then destination.
 *
 *   categories → CategoryModel::duplicate($src_project_id, $dst_project_id)
 *                  app/Model/CategoryModel.php:209
 *
 *   swimlanes  → SwimlaneModel::duplicate($projectSrcId, $projectDstId)
 *                  app/Model/SwimlaneModel.php:437
 *
 * @package  Kanboard\Plugin\FeatureSync\Model
 * @author   Carmelo Santana
 */
class FeatureSyncModel extends Base
{
    /**
     * Supported feature keys (these are the checkbox names in the UI).
     */
    const FEATURE_ACTIONS    = 'actions';
    const FEATURE_TAGS       = 'tags';
    const FEATURE_COLUMNS    = 'columns';
    const FEATURE_CATEGORIES = 'categories';
    const FEATURE_SWIMLANES  = 'swimlanes';

    /**
     * Return the ordered list of supported feature labels keyed by feature constant.
     *
     * @return string[]
     */
    public function getFeatureList()
    {
        return array(
            self::FEATURE_ACTIONS    => t('Automated Actions'),
            self::FEATURE_TAGS       => t('Tags'),
            self::FEATURE_COLUMNS    => t('Board Columns'),
            self::FEATURE_CATEGORIES => t('Categories'),
            self::FEATURE_SWIMLANES  => t('Swimlanes'),
        );
    }

    /**
     * Return the copier map: feature key → [core_class, core_method, arg_order].
     *
     * Each entry documents (with exact class + method verified against the installed
     * core) what task-05's apply step will call.
     *
     *   arg_order: 'src_dst'  means the method takes ($src_project_id, $dst_project_id)
     *              'from_to'  means the method takes ($project_from, $project_to)
     *                         — same semantics, different parameter names (BoardModel).
     *
     * Core file:line references (kanboard-1.2.47):
     *   ActionModel::duplicate          app/Model/ActionModel.php:171
     *   TagDuplicationModel::duplicate  app/Model/TagDuplicationModel.php:23
     *   BoardModel::duplicate           app/Model/BoardModel.php:87
     *   CategoryModel::duplicate        app/Model/CategoryModel.php:209
     *   SwimlaneModel::duplicate        app/Model/SwimlaneModel.php:437
     *
     * @return array[]
     */
    public function getCopierMap()
    {
        return array(
            // Actions: copies all automated actions (event + action_name) and their
            // parameters from the actions / action_has_params tables.
            // Core: ActionModel::duplicate($src_project_id, $dst_project_id)
            //       app/Model/ActionModel.php:171
            self::FEATURE_ACTIONS => array(
                'container_key' => 'actionModel',
                'class'         => 'Kanboard\Model\ActionModel',
                'method'        => 'duplicate',
                'arg_order'     => 'src_dst',
                'description'   => 'ActionModel::duplicate($src, $dst) — copies actions + params',
            ),

            // Tags: copies project-level tags (name + color) to the target project.
            // Core: TagDuplicationModel::duplicate($src_project_id, $dst_project_id)
            //       app/Model/TagDuplicationModel.php:23
            self::FEATURE_TAGS => array(
                'container_key' => 'tagDuplicationModel',
                'class'         => 'Kanboard\Model\TagDuplicationModel',
                'method'        => 'duplicate',
                'arg_order'     => 'src_dst',
                'description'   => 'TagDuplicationModel::duplicate($src, $dst) — copies project tags',
            ),

            // Columns: copies board columns (title, task_limit, description, hide_in_dashboard).
            // Core: BoardModel::duplicate($project_from, $project_to)
            //       app/Model/BoardModel.php:87
            // NOTE: param names are project_from / project_to, semantics = src / dst.
            self::FEATURE_COLUMNS => array(
                'container_key' => 'boardModel',
                'class'         => 'Kanboard\Model\BoardModel',
                'method'        => 'duplicate',
                'arg_order'     => 'src_dst',  // project_from=src, project_to=dst
                'description'   => 'BoardModel::duplicate($project_from, $project_to) — copies columns',
            ),

            // Categories: copies task categories (name, description, color_id).
            // Core: CategoryModel::duplicate($src_project_id, $dst_project_id)
            //       app/Model/CategoryModel.php:209
            self::FEATURE_CATEGORIES => array(
                'container_key' => 'categoryModel',
                'class'         => 'Kanboard\Model\CategoryModel',
                'method'        => 'duplicate',
                'arg_order'     => 'src_dst',
                'description'   => 'CategoryModel::duplicate($src, $dst) — copies categories',
            ),

            // Swimlanes: copies swimlane definitions (name, description, position).
            // Core: SwimlaneModel::duplicate($projectSrcId, $projectDstId)
            //       app/Model/SwimlaneModel.php:437
            self::FEATURE_SWIMLANES => array(
                'container_key' => 'swimlaneModel',
                'class'         => 'Kanboard\Model\SwimlaneModel',
                'method'        => 'duplicate',
                'arg_order'     => 'src_dst',
                'description'   => 'SwimlaneModel::duplicate($projectSrcId, $projectDstId) — copies swimlanes',
            ),
        );
    }

    /**
     * Resolve and normalise the POST form parameters for the Feature Sync page.
     *
     * Encapsulates the controller's three resolution rules so they can be unit-tested
     * independently of the HTTP stack:
     *
     *   1. source_project_id < 1  → clamped to 0 (no selection).
     *   2. target_project_ids     → cast to int, id=0 and id=source stripped, re-indexed.
     *   3. sync_mode              → defaults to 'add_missing'; invalid values clamped to it.
     *
     * @param  array $post             Raw POST values (e.g. from request->getValues()).
     * @param  int   $sourceFromGet    Fallback source project id from the GET parameter
     *                                 (used when source_project_id is absent from POST).
     * @return array{
     *     sourceProjectId:  int,
     *     selectedFeatures: list<string>,
     *     targetProjectIds: list<int>,
     *     syncMode:         string,
     * }
     */
    public function resolveFormParams(array $post, $sourceFromGet = 0)
    {
        // --- 1. Source project id ---
        $sourceProjectId = isset($post['source_project_id'])
            ? (int) $post['source_project_id']
            : (int) $sourceFromGet;

        // Guard: id < 1 is invalid ("None" / not chosen).
        if ($sourceProjectId < 1) {
            $sourceProjectId = 0;
        }

        // --- 2. Selected feature checkboxes ---
        $selectedFeatures = isset($post['features']) ? $post['features'] : array();
        if (! is_array($selectedFeatures)) {
            $selectedFeatures = array($selectedFeatures);
        }

        // --- 3. Target project ids ---
        $rawTargetIds = isset($post['target_project_ids']) ? $post['target_project_ids'] : array();
        if (! is_array($rawTargetIds)) {
            $rawTargetIds = array($rawTargetIds);
        }

        $src = $sourceProjectId;  // captured for the closure
        $targetProjectIds = array_values(array_filter(
            array_map('intval', $rawTargetIds),
            function ($id) use ($src) {
                return $id > 0 && $id !== $src;
            }
        ));

        // --- 4. Sync mode ---
        $syncMode = isset($post['sync_mode']) ? $post['sync_mode'] : 'add_missing';
        if (! in_array($syncMode, array('add_missing', 'replace'), true)) {
            $syncMode = 'add_missing';
        }

        return array(
            'sourceProjectId'  => $sourceProjectId,
            'selectedFeatures' => $selectedFeatures,
            'targetProjectIds' => $targetProjectIds,
            'syncMode'         => $syncMode,
        );
    }

    /**
     * Read the source items for a given feature (READ-ONLY).
     *
     * Match keys by feature:
     *   actions    → "{event_name}::{action_name}"  (event + action class uniquely identify an action config)
     *   tags       → tag name  (case-sensitive, as stored)
     *   columns    → column title
     *   categories → category name
     *   swimlanes  → swimlane name
     *
     * Core read methods verified against kanboard-1.2.47:
     *   ActionModel::getAllByProject($project_id)    app/Model/ActionModel.php:50
     *   TagModel::getAllByProject($project_id)       app/Model/TagModel.php:40
     *   ColumnModel::getAll($project_id)            app/Model/ColumnModel.php:118
     *   CategoryModel::getAll($project_id)          app/Model/CategoryModel.php:122
     *   SwimlaneModel::getAll($projectId)           app/Model/SwimlaneModel.php:133
     *
     * @param  string  $feature    One of the FEATURE_* constants.
     * @param  integer $project_id Project to read items from.
     * @return array[]             Rows from the database; each row is an associative array.
     */
    public function getFeatureItems($feature, $project_id)
    {
        switch ($feature) {
            case self::FEATURE_ACTIONS:
                // ActionModel::getAllByProject returns actions with their parameters attached.
                // app/Model/ActionModel.php:50
                return $this->actionModel->getAllByProject($project_id);

            case self::FEATURE_TAGS:
                // TagModel::getAllByProject returns rows: id, project_id, name, color_id
                // app/Model/TagModel.php:40
                return $this->tagModel->getAllByProject($project_id);

            case self::FEATURE_COLUMNS:
                // ColumnModel::getAll returns rows: id, project_id, title, position, task_limit,
                // description, hide_in_dashboard — ordered by position asc.
                // app/Model/ColumnModel.php:118
                return $this->columnModel->getAll($project_id);

            case self::FEATURE_CATEGORIES:
                // CategoryModel::getAll returns rows: id, project_id, name, description, color_id
                // app/Model/CategoryModel.php:122
                return $this->categoryModel->getAll($project_id);

            case self::FEATURE_SWIMLANES:
                // SwimlaneModel::getAll returns all swimlanes (active + inactive) ordered by position.
                // app/Model/SwimlaneModel.php:133
                return $this->swimlaneModel->getAll($project_id);

            default:
                throw new \InvalidArgumentException("FeatureSyncModel: unknown feature '{$feature}'");
        }
    }

    /**
     * Return the match key for an item of a given feature (used for add/skip comparison).
     *
     * Match-key rationale per feature:
     *   actions    → event_name + "::" + action_name  (uniquely identifies an action type)
     *   tags       → name
     *   columns    → title
     *   categories → name
     *   swimlanes  → name
     *
     * @param  string $feature One of the FEATURE_* constants.
     * @param  array  $item    One row from getFeatureItems().
     * @return string
     */
    public function getItemKey($feature, array $item)
    {
        switch ($feature) {
            case self::FEATURE_ACTIONS:
                return $item['event_name'] . '::' . $item['action_name'];
            case self::FEATURE_TAGS:
                return $item['name'];
            case self::FEATURE_COLUMNS:
                return $item['title'];
            case self::FEATURE_CATEGORIES:
                return $item['name'];
            case self::FEATURE_SWIMLANES:
                return $item['name'];
            default:
                throw new \InvalidArgumentException("FeatureSyncModel: unknown feature '{$feature}'");
        }
    }

    /**
     * Compute a read-only diff for one feature between source and one target project.
     *
     * Returns a structured result per feature:
     *
     *   add_missing mode:
     *     'add'  — source items absent from target (would be added on apply)
     *     'skip' — source items already present in target (no-op on apply)
     *
     *   replace mode:
     *     'replace_add'    — source items that would be added (full source set)
     *     'replace_remove' — target items that would be removed (full target set)
     *     For counting: add=count(replace_add), skip=0, replace=count(replace_remove)
     *
     * NO WRITES — this method only SELECTs from the database.
     *
     * @param  string  $feature          One of the FEATURE_* constants.
     * @param  integer $sourceProjectId  Source project ID.
     * @param  integer $targetProjectId  Target project ID.
     * @param  string  $mode             'add_missing' or 'replace'.
     * @return array{
     *     feature:        string,
     *     mode:           string,
     *     source_items:   array[],
     *     target_items:   array[],
     *     add:            array[],
     *     skip:           array[],
     *     replace_add:    array[],
     *     replace_remove: array[],
     *     count_add:      int,
     *     count_skip:     int,
     *     count_replace:  int,
     * }
     */
    public function diffFeature($feature, $sourceProjectId, $targetProjectId, $mode)
    {
        // READ source and target items — no writes.
        $sourceItems = $this->getFeatureItems($feature, $sourceProjectId);
        $targetItems = $this->getFeatureItems($feature, $targetProjectId);

        // Build a lookup set of target match-keys for O(1) membership checks.
        $targetKeySet = array();
        foreach ($targetItems as $item) {
            $targetKeySet[$this->getItemKey($feature, $item)] = true;
        }

        $add  = array();
        $skip = array();

        foreach ($sourceItems as $item) {
            $key = $this->getItemKey($feature, $item);
            if (isset($targetKeySet[$key])) {
                $skip[] = $item;
            } else {
                $add[] = $item;
            }
        }

        if ($mode === 'replace') {
            // Replace mode: the full source set would be added after the target set is cleared.
            return array(
                'feature'        => $feature,
                'mode'           => $mode,
                'source_items'   => $sourceItems,
                'target_items'   => $targetItems,
                'add'            => array(),
                'skip'           => array(),
                'replace_add'    => $sourceItems,
                'replace_remove' => $targetItems,
                'count_add'      => count($sourceItems),
                'count_skip'     => 0,
                'count_replace'  => count($targetItems),
            );
        }

        // add_missing mode
        return array(
            'feature'        => $feature,
            'mode'           => $mode,
            'source_items'   => $sourceItems,
            'target_items'   => $targetItems,
            'add'            => $add,
            'skip'           => $skip,
            'replace_add'    => array(),
            'replace_remove' => array(),
            'count_add'      => count($add),
            'count_skip'     => count($skip),
            'count_replace'  => 0,
        );
    }

    /**
     * Compute a full read-only diff for all selected features across all target projects.
     *
     * Returns a map: targetProjectId → [feature → diffFeature result].
     *
     * NO WRITES — this method only SELECTs from the database.
     *
     * @param  integer   $sourceProjectId  Source project ID.
     * @param  integer[] $targetProjectIds List of target project IDs.
     * @param  string[]  $features         List of FEATURE_* constants to diff.
     * @param  string    $mode             'add_missing' or 'replace'.
     * @return array                        [targetProjectId => [feature => diff result]]
     */
    public function diff($sourceProjectId, array $targetProjectIds, array $features, $mode)
    {
        $result = array();

        foreach ($targetProjectIds as $targetProjectId) {
            $targetProjectId = (int) $targetProjectId;
            $result[$targetProjectId] = array();

            foreach ($features as $feature) {
                $result[$targetProjectId][$feature] = $this->diffFeature(
                    $feature,
                    $sourceProjectId,
                    $targetProjectId,
                    $mode
                );
            }
        }

        return $result;
    }

    /**
     * Check whether any of the target columns that would be removed in 'replace' mode
     * contain open tasks (i.e. a risky replace-columns operation).
     *
     * Uses TaskFinderModel::countByColumnId() — READ-ONLY.
     * Core: app/Model/TaskFinderModel.php:358
     *
     * @param  integer $targetProjectId   Target project ID.
     * @param  array[] $targetColumns     Columns from getFeatureItems('columns', $targetProjectId).
     * @return array                       [column_id => task_count] only for columns with tasks > 0.
     */
    public function getColumnsWithTasks($targetProjectId, array $targetColumns)
    {
        $risky = array();

        foreach ($targetColumns as $col) {
            $count = $this->taskFinderModel->countByColumnId(
                $targetProjectId,
                (int) $col['id']
            );
            if ($count > 0) {
                $risky[(int)$col['id']] = array(
                    'column' => $col,
                    'task_count' => $count,
                );
            }
        }

        return $risky;
    }

    /**
     * Copy a single feature from source project to destination project.
     *
     * STUB — implementation comes in task-05.  The copier map is already wired;
     * this method is here so the container key and method reference are exercised.
     *
     * @param  string  $feature         One of the FEATURE_* constants.
     * @param  integer $src_project_id  Source project ID.
     * @param  integer $dst_project_id  Destination project ID.
     * @param  string  $mode            'add_missing' (default) or 'replace'.
     * @return never
     * @throws \InvalidArgumentException when $feature is not in the copier map.
     * @throws \RuntimeException         always — implementation stub for task-05.
     */
    public function copyFeature($feature, $src_project_id, $dst_project_id, $mode = 'add_missing')
    {
        $map = $this->getCopierMap();

        if (! isset($map[$feature])) {
            throw new \InvalidArgumentException("FeatureSyncModel: unknown feature '{$feature}'");
        }

        // task-05 will implement the actual logic here (add_missing / replace modes).
        throw new \RuntimeException("FeatureSyncModel::copyFeature() — not yet implemented (task-05)");
    }
}
