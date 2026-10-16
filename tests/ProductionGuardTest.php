<?php

namespace Langsys\Laravel\Tests;

use Langsys\SDK\Client;

/**
 * Tests never call the production API. `phpunit.xml` points a client built with no URL of its own
 * at a closed local port, and `TestCase` fails a test whose client is aimed at production anyway.
 * These are the guard's firing controls.
 */
class ProductionGuardTest extends TestCase
{
    public function testAClientBuiltWithNoUrlReachesNoServer(): void
    {
        $this->assertSame('http://127.0.0.1:9', getenv('LANGSYS_API_URL'));
        $this->assertSame('http://127.0.0.1:9', (new Client('test-key', 'test-project'))->getConfig()->getApiUrl());

        $this->app->forgetInstance(Client::class);
        $this->assertSame('http://127.0.0.1:9', $this->app->make(Client::class)->getConfig()->getApiUrl(), "The provider's client, from the package's config.");
    }

    public function testAClientAimedAtProductionFailsTheTest(): void
    {
        config()->set('langsys.api_url', 'https://api.langsys.dev/api');
        $this->app->forgetInstance(Client::class);

        try {
            $this->app->make(Client::class);
            $this->fail('The guard lets a production client through.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('production API', $e->getMessage());
        }

        $this->assertSame(['https://api.langsys.dev/api'], $this->takeProductionClients(), 'Recorded, so the test fails even where the exception is swallowed.');
    }

    public function testAnOfflineClientAimedAtProductionFailsTheTest(): void
    {
        try {
            $this->offlineClient([], ['api_url' => 'https://API.langsys.dev/api']);
            $this->fail('The guard lets a production client through.');
        } catch (\LogicException) {
        }

        $this->assertSame(['https://API.langsys.dev/api'], $this->takeProductionClients());
    }
}
