<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;

class DirectoryClientTest extends Base
{
    private $client;

    public function setUp(): void
    {
        parent::setUp();
        $this->client = new DirectoryClient($this->container);
    }

    public function testResolveAssetUrlKeepsAbsolute()
    {
        $this->assertSame(
            'https://cdn.example.com/a.png',
            DirectoryClient::resolveAssetUrl('https://cdn.example.com/a.png', 'https://x.com/dir/plugins.json')
        );
    }

    public function testResolveAssetUrlJoinsRelative()
    {
        $this->assertSame(
            'https://x.com/dir/assets/a.png',
            DirectoryClient::resolveAssetUrl('assets/a.png', 'https://x.com/dir/plugins.json')
        );
    }

    public function testAnnotateMarksAvailable()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', []);
        $this->assertSame('available', $out[0]['status']);
    }

    public function testAnnotateMarksInstalled()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('installed', $out[0]['status']);
    }

    public function testAnnotateMarksUpdate()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.1.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('update', $out[0]['status']);
    }

    public function testAnnotateMarksDisabled()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'disabled']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('disabled', $out[0]['status']);
    }

    public function testAnnotateDisabledWinsOverUpdate()
    {
        $plugins = [['name' => 'Foo', 'version' => '2.0.0']]; // newer in the listing
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'disabled']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('disabled', $out[0]['status']); // disabled wins, not 'update'
    }

    public function testAnnotateResolvesScreenshots()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0', 'screenshots' => ['assets/s1.png']]];
        $out = $this->client->annotate($plugins, 'https://x.com/dir/plugins.json', []);
        $this->assertSame(['https://x.com/dir/assets/s1.png'], $out[0]['screenshots']);
    }

    public function testMergeDedupesByNameFirstSourceWins()
    {
        $sourcesData = [
            ['url' => 'https://a.com/plugins.json', 'plugins' => [['name' => 'Foo', 'version' => '1.0.0']]],
            ['url' => 'https://b.com/plugins.json', 'plugins' => [['name' => 'Foo', 'version' => '9.9.9']]],
        ];
        $merged = $this->client->merge($sourcesData, []);
        $this->assertCount(1, $merged);
        $this->assertSame('1.0.0', $merged[0]['version']);
    }
}
