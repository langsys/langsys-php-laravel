<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Cache\LaravelCacheAdapter;

class LaravelCacheAdapterTest extends TestCase
{
    private LaravelCacheAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new LaravelCacheAdapter($this->app['cache']->store('array'), 'langsys:', 3600);
    }

    public function testSetGetHasDeleteRoundTrip(): void
    {
        $this->assertFalse($this->adapter->has('translations_en'));

        $this->adapter->set('translations_en', ['UI' => ['Save' => 'Guardar']], 3600);

        $this->assertTrue($this->adapter->has('translations_en'));
        $this->assertSame(['UI' => ['Save' => 'Guardar']], $this->adapter->get('translations_en'));

        $this->adapter->delete('translations_en');

        $this->assertFalse($this->adapter->has('translations_en'));
        $this->assertNull($this->adapter->get('translations_en'));
    }

    public function testKeysArePrefixedInTheUnderlyingStore(): void
    {
        $this->adapter->set('foo', 'bar', 3600);

        $this->assertSame('bar', $this->app['cache']->store('array')->get('langsys:foo'));
    }

    public function testNonPositiveTtlStoresForever(): void
    {
        $this->adapter->set('permanent', 'value', 0);

        $this->assertSame('value', $this->adapter->get('permanent'));
    }

    public function testClearOnlyEvictsAdapterKeys(): void
    {
        $store = $this->app['cache']->store('array');
        $store->put('app_key', 'untouched', 3600);

        $this->adapter->set('one', 1, 3600);
        $this->adapter->set('two', 2, 3600);

        $this->assertTrue($this->adapter->clear());

        $this->assertNull($this->adapter->get('one'));
        $this->assertNull($this->adapter->get('two'));
        $this->assertSame('untouched', $store->get('app_key'));
    }
}
