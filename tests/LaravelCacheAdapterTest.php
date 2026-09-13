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

    /**
     * CACHE-1. clear() walks a key index, and two projects sharing a store and
     * prefix — the default prefix, on a shared Redis — used to share that
     * index, so clearing one project's cache evicted the other's catalog.
     */
    public function testClearingOneProjectLeavesAnotherProjectsKeys(): void
    {
        $store = $this->app['cache']->store('array');
        $a = new LaravelCacheAdapter($store, 'langsys:', 3600, 'project-a');
        $b = new LaravelCacheAdapter($store, 'langsys:', 3600, 'project-b');

        $a->set('translations_project-a_es-es', ['UI' => ['Save' => 'Guardar']]);
        $b->set('translations_project-b_es-es', ['UI' => ['Save' => 'Salvar']]);

        $a->clear();

        $this->assertNull($a->get('translations_project-a_es-es'), "Control: clear() must still evict its own project's keys.");
        $this->assertSame(['UI' => ['Save' => 'Salvar']], $b->get('translations_project-b_es-es'));
    }

    /**
     * The SDK writes its catalog without a TTL, and CacheInterface defaults
     * that argument to 3600 — so an adapter defaulting to the same value never
     * reached `langsys.cache.ttl`, and the setting did nothing.
     */
    public function testTheConfiguredTtlAppliesWhenTheSdkPassesNone(): void
    {
        $adapter = new LaravelCacheAdapter($this->app['cache']->store('array'), 'langsys:', 7200);

        $adapter->set('translations_en', ['UI' => ['Save' => 'Save']]);
        $this->travel(3601)->seconds();

        $this->assertNotNull($adapter->get('translations_en'), 'Expired at the interface default, not the configured TTL.');

        $this->travel(3600)->seconds();

        $this->assertNull($adapter->get('translations_en'), 'Control: the configured TTL must still expire the entry.');
    }

    /**
     * BIND-5 and CAT-1: a registered-but-untranslated phrase is present with a
     * null value, and has to come back as exactly that. A store that dropped
     * the key would turn "registered, translation running" into a miss, and
     * the SDK would register it again.
     */
    public function testPresentWithNullSurvivesTheRoundTrip(): void
    {
        $this->adapter->set('translations_es-es', ['UI' => ['Save' => null]]);

        $catalog = $this->adapter->get('translations_es-es');

        $this->assertArrayHasKey('Save', $catalog['UI']);
        $this->assertNull($catalog['UI']['Save']);
    }
}
