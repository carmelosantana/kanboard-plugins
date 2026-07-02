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
     * Renders the 5-step workflow. Steps 1 (source project) and 2 (feature types)
     * are wired here; steps 3-5 come in later tasks.
     *
     * The form posts back to this same action so the controller can re-render with
     * the user's selections retained (source project + feature checkboxes).
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
        $projects = $this->projectModel->getList(true, false);

        // Feature options from the model (keys = checkbox names, values = labels).
        $featureList = $featureSyncModel->getFeatureList();

        // Retain form state across POST round-trips.
        // source_project_id is a POST form value (read via getValue) when the form
        // is submitted; it may also be a GET param on first load — fall back to 0.
        $postValues = $this->request->getValues();  // validated POST (CSRF-checked)
        $sourceProjectId = isset($postValues['source_project_id'])
            ? (int) $postValues['source_project_id']
            : (int) $this->request->getIntegerParam('source_project_id', 0);
        $selectedFeatures = isset($postValues['features']) ? $postValues['features'] : array();

        // Ensure it is always an array even if a single checkbox value is posted.
        if (! is_array($selectedFeatures)) {
            $selectedFeatures = array($selectedFeatures);
        }

        $this->response->html($this->helper->layout->config('FeatureSync:sync/index', array(
            'title'            => t('Settings') . ' &gt; ' . t('Feature Sync'),
            'projects'         => $projects,
            'featureList'      => $featureList,
            'sourceProjectId'  => $sourceProjectId,
            'selectedFeatures' => $selectedFeatures,
        )));
    }
}
