<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\LangsysServiceProvider;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Client;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected FakeClient $fakeClient;

    protected function getPackageProviders($app): array
    {
        return [LangsysServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('langsys.api_key', 'test-key');
        $app['config']->set('langsys.project_id', 'test-project');
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeClient = new FakeClient();
        $this->app->instance(Client::class, $this->fakeClient);
    }
}
