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
    }

    public function testConfigIsMerged(): void
    {
        $this->assertSame('https://api.langsys.dev/api', config('langsys.api_url'));
        $this->assertSame('langsys:', config('langsys.cache.prefix'));
        $this->assertTrue(config('langsys.auto_flush'));
    }
}
