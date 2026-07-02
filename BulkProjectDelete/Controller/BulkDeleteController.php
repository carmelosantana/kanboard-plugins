<?php

namespace Kanboard\Plugin\BulkProjectDelete\Controller;

use Kanboard\Controller\BaseController;

class BulkDeleteController extends BaseController
{
    /**
     * GET — show a confirmation page listing the selected projects and their impact.
     * (stub: empty response until task-04 implements the preflight)
     */
    public function confirm()
    {
        $this->response->html('');
    }

    /**
     * POST — perform the bulk delete.
     * (stub: empty response until task-05 implements the delete endpoint)
     */
    public function remove()
    {
        $this->response->html('');
    }
}
