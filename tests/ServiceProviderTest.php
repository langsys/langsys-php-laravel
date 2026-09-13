<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Cache\LaravelCacheAdapter;
use Langsys\Laravel\LangsysTranslator;
use Langsys\SDK\Client;

class ServiceProviderTest extends TestCase
{
    public function testClientSingletonIsBuiltFromConfigWithTheLaravelCacheAdapter(): void
    {
        $this->app->forgetInstance(Client::class);

        $client = $this->app->make(Client::class);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(LaravelCacheAdapter::class, $client->getCache());
        $this->assertSame($client, $this->app->make(Client::class));
    }

    public function testTranslatorAndHelperAreAvailable(): void
    {
        $this->assertInstanceOf(LangsysTranslator::class, $this->app->make(LangsysTranslator::class));
        $this->assertTrue(function_exists('t'));
    }

    public function testMiddlewareAliasesAreRegistered(): void
    {
        $aliases = $this->app['router']->getMiddleware();

        $this->assertArrayHasKey('langsys.locale', $aliases);
        $this->assertArrayHasKey('langsys.flush', $aliases);
        $this->assertArrayHasKey('langsys.translate-page', $aliases);
    }

    public function testConfigIsMerged(): void
    {
        $this->assertSame('https://api.langsys.dev/api', config('langsys.api_url'));
        $this->assertSame('langsys:', config('langsys.cache.prefix'));
    }

    /**
     * CACHE-1 on the path an application actually takes: the adapter the
     * provider builds is scoped to the configured project, so clearing this
     * project's cache leaves another project on the same store and prefix
     * alone.
     */
    public function testTheProviderScopesTheCacheIndexToTheProject(): void
    {
        $this->app->forgetInstance(Client::class);
        $cache = $this->app->make(Client::class)->getCache();

        $other = new LaravelCacheAdapter($this->app['cache']->store(), 'langsys:', 3600, 'another-project');
        $other->set('translations_another-project_es-es', ['UI' => ['Save' => 'Salvar']]);
        $cache->set('translations_test-project_es-es', ['UI' => ['Save' => 'Guardar']]);

        $cache->clear();

        $this->assertNull($cache->get('translations_test-project_es-es'), "Control: clear() must still evict this project's keys.");
        $this->assertSame(['UI' => ['Save' => 'Salvar']], $other->get('translations_another-project_es-es'));
    }

    /**
     * WIRE-5 through Laravel's config: `langsys.api_url` is where the SDK
     * connects, observed as the address a real connection attempt names.
     */
    public function testTheConfiguredApiUrlIsWhereTheSdkConnects(): void
    {
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);

        $this->expectExceptionMessageMatches('/127\.0\.0\.1 port 9\b/');

        $this->app->make(Client::class)->getTranslations('es-es');
    }

    /**
     * WIRE-5's ordering half: the URL is read when the Client is first built,
     * so changing it afterwards reaches nothing. A test double has to be
     * configured before anything translates.
     */
    public function testAnApiUrlChangedAfterTheClientIsBuiltIsNotUsed(): void
    {
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);
        $client = $this->app->make(Client::class);

        config()->set('langsys.api_url', 'http://127.0.0.1:10');

        $this->expectExceptionMessageMatches('/127\.0\.0\.1 port 9\b/');

        $client->getTranslations('es-es');
    }
}
