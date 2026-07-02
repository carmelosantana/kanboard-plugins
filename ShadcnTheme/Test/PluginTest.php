<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\ShadcnTheme\Plugin;
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
        $this->assertSame('ShadcnTheme', $plugin->getPluginName());
    }

    public function testPluginAuthor()
    {
        $plugin = new Plugin($this->container);
        $this->assertNotEmpty($plugin->getPluginAuthor());
    }
}
