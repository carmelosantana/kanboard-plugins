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
