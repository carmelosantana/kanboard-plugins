<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('SchedulerPlugin', $plugin->getPluginName());
    }

    public function testPluginCompatibleVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testPluginMetadataNotEmpty()
    {
        $plugin = new Plugin($this->container);
        $this->assertNotEmpty($plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertSame('MIT', $plugin->getPluginLicense());
    }
}
