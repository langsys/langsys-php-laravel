<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Cache\LaravelCacheAdapter;
use Langsys\Laravel\LangsysServiceProvider;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\Laravel\Tests\Fakes\RecordingClient;
use Langsys\SDK\Client;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * A closed port on this machine. A request the SDK was not supposed to make
     * fails here in about a millisecond instead of reaching a real project.
     */
    protected const UNREACHABLE_API = 'http://127.0.0.1:9';

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

    /**
     * The real SDK Client for evidence that has to be the SDK's own code path
     * rather than FakeClient's stand-in for it. Catalogs are seeded into the
     * Laravel cache it reads through, and the API base is a closed local port.
     *
     * @param array<string, array<string, array<string, ?string>>> $catalogs locale => category => phrase => translation
     */
    protected function offlineClient(array $catalogs = []): RecordingClient
    {
        $client = new RecordingClient('test-key', 'test-project', [
            'api_url'                   => self::UNREACHABLE_API,
            'cache'                     => new LaravelCacheAdapter($this->app['cache']->store('array'), 'langsys:', 3600, 'test-project'),
            'warn_runtime_requirements' => false,
        ]);

        foreach ($catalogs as $locale => $catalog) {
            $client->getCache()->set(self::_catalogKey($locale), $catalog);
        }

        return $client;
    }

    /** Moves the shared catalog on, as a later request would find it. */
    protected function seedCatalog(string $locale, array $catalog): void
    {
        $this->app->make(Client::class)->getCache()->set(self::_catalogKey($locale), $catalog);
    }

    /**
     * The SDK's own catalog key. If its shape changes, seeded catalogs stop
     * being found, lookups fall through to the closed port and degrade to
     * source text — so tests seeding through here go red rather than passing
     * against nothing.
     */
    private static function _catalogKey(string $locale): string
    {
        return 'translations_test-project_' . $locale;
    }
}
