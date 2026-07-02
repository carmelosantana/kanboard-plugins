<?php

namespace Kanboard\Plugin\FeatureSync\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\FeatureSync\Model\FeatureSyncModel;

class FeatureSyncController extends BaseController
{
    /**
     * GET/POST — admin-only Feature Sync page.
     *
     * Renders the 5-step workflow. Steps 1 (source project), 2 (feature types),
     * and 3 (target projects + mode) are wired here; steps 4-5 come in later tasks.
     *
     * The form posts back to this same action so the controller can re-render with
     * the user's selections retained (source project + feature checkboxes + targets + mode).
     *
     * @throws AccessForbiddenException when the current user is not an app admin
     */
    public function index()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        /** @var FeatureSyncModel $featureSyncModel */
        $featureSyncModel = $this->container['featureSyncModel'];

        // All non-private projects for the source dropdown.
        // ProjectModel::getList($prependNone, $noPrivateProjects)
        //   app/Model/ProjectModel.php:233
        // Use prependNone=false to avoid the id=0 "None" landmine.
        $projects = $this->projectModel->getList(false, false);

        // Feature options from the model (keys = checkbox names, values = labels).
        $featureList = $featureSyncModel->getFeatureList();

        // Retain form state across POST round-trips.
        // Delegate all resolution logic to the model helper (also unit-tested there).
        $postValues = $this->request->getValues();  // validated POST (CSRF-checked)
        $sourceFromGet = $this->request->getIntegerParam('source_project_id', 0);

        $resolved = $featureSyncModel->resolveFormParams($postValues, $sourceFromGet);

        $sourceProjectId  = $resolved['sourceProjectId'];
        $selectedFeatures = $resolved['selectedFeatures'];
        $targetProjectIds = $resolved['targetProjectIds'];
        $syncMode         = $resolved['syncMode'];

        // Target project list = all projects except the source.
        $targetProjects = array();
        foreach ($projects as $id => $name) {
            $id = (int) $id;
            if ($id > 0 && $id !== $sourceProjectId) {
                $targetProjects[$id] = $name;
            }
        }

        $this->response->html($this->helper->layout->config('FeatureSync:sync/index', array(
            'title'             => t('Settings') . ' &gt; ' . t('Feature Sync'),
            'projects'          => $projects,
            'featureList'       => $featureList,
            'sourceProjectId'   => $sourceProjectId,
            'selectedFeatures'  => $selectedFeatures,
            'targetProjects'    => $targetProjects,
            'targetProjectIds'  => $targetProjectIds,
            'syncMode'          => $syncMode,
        )));
    }
}
