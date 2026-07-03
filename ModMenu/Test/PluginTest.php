<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\ModMenu\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('ModMenu', $plugin->getPluginName());
    }

    public function testPluginVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
    }

    public function testCompatibleVersion()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testInitializeRegistersRoutesWithoutError()
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $this->assertNotEmpty($plugin->getPluginDescription());
    }
}
