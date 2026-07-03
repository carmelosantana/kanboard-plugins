<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

class SourceRepositoryTest extends Base
{
    private $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceRepository($this->container);
    }

    public function testDefaultsToBundledSourceWhenUnset()
    {
        $sources = $this->repo->getSources();
        $this->assertSame([SourceRepository::DEFAULT_SOURCE], $sources);
    }

    public function testAddSourcePersists()
    {
        $this->repo->addSource('https://example.com/plugins.json');
        $this->assertContains('https://example.com/plugins.json', $this->repo->getSources());
    }

    public function testAddSourceIsDeduped()
    {
        $this->repo->addSource('https://example.com/plugins.json');
        $this->repo->addSource('https://example.com/plugins.json');
        $count = count(array_keys($this->repo->getSources(), 'https://example.com/plugins.json'));
        $this->assertSame(1, $count);
    }

    public function testAddSourceRejectsNonHttp()
    {
        $this->expectException(ModMenuException::class);
        $this->repo->addSource('ftp://example.com/x.json');
    }

    public function testRemoveSource()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->repo->addSource('https://example.com/b.json');
        $this->repo->removeSource('https://example.com/a.json');
        $sources = $this->repo->getSources();
        $this->assertNotContains('https://example.com/a.json', $sources);
        $this->assertContains('https://example.com/b.json', $sources);
    }

    public function testRemovingAllYieldsEmptyNotDefault()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->repo->removeSource('https://example.com/a.json');
        $this->repo->removeSource(SourceRepository::DEFAULT_SOURCE);
        $this->assertSame([], $this->repo->getSources());
    }
}
