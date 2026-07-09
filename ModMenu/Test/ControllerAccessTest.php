<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Controller\ModMenuController;
use Kanboard\Core\Controller\AccessForbiddenException;

class ControllerAccessTest extends Base
{
    private function nonAdminController(): ModMenuController
    {
        // No user session => userSession->isAdmin() is false.
        return new ModMenuController($this->container);
    }

    public function testShowForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->show();
    }

    public function testDirectoryForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->directory();
    }

    public function testSourcesForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->sources();
    }

    public function testUploadForbiddenForNonAdmin()
    {
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        (new \Kanboard\Plugin\ModMenu\Controller\UploadController($this->container))->upload();
    }

    public function testResolveForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->resolve();
    }
}
