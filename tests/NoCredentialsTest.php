<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Translation\Translator;
use Langsys\Laravel\Support\ClientState;
use Langsys\Laravel\Translation\CatalogTranslator;
use Langsys\SDK\Client;

/**
 * FRM-1 and FRM-3: installing the package never breaks the app. With no API key — an app's own tests, CI, a
 * fresh checkout — the catalog is empty by definition, so every translate call is exactly what
 * plain Laravel returns, the pages and forms that use this package's middleware still serve, and
 * no client is ever built (the core's constructor throws without credentials). The cause is
 * reported once per process, at debug (FRM-3).
 */
class NoCredentialsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.api_key', null);
        $app['config']->set('langsys.project_id', null);
        $app->useLangPath(__DIR__ . '/Fixtures/lang');
        $app['config']->set('langsys.translate_response.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'langsys.locale', 'langsys.translate-page', 'langsys.flush'])->get('/pricing', fn () => Blade::render('<p>{{ __(\'Hello :name\', [\'name\' => \'Ana\']) }}</p><p>@lang(\'messages.welcome\', [\'name\' => \'Ana\'])</p>'));
        $router->middleware('api')->post('/api/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // TestCase binds a fake client; this app has none, as an app without a key has none.
        $this->app->forgetInstance(Client::class);
        (fn () => self::$noticed = false)->bindTo(null, ClientState::class)();
    }

    public function testEveryCallReturnsPlainLaravelsOutput(): void
    {
        $plain = new Translator($this->app['translation.loader'], 'en');
        $plain->setFallback('en');

        $this->assertInstanceOf(CatalogTranslator::class, $this->app['translator'], 'Installed: the catalog translator is in place.');

        foreach ([
            ['Hello :name', ['name' => 'Ana']],
            ['messages.welcome', ['name' => 'Ana']],
            ['Save', []],
            ['messages.nope', []],
        ] as [$key, $replace]) {
            $this->assertSame($plain->get($key, $replace), __($key, $replace), $key);
        }

        $this->assertSame($plain->choice('messages.apples', 3), trans_choice('messages.apples', 3));
        $this->assertSame($plain->has('messages.welcome'), $this->app['translator']->has('messages.welcome'));
        $this->assertSame($plain->get('messages.welcome', ['name' => 'Ana']), trim(Blade::render("@lang('messages.welcome', ['name' => 'Ana'])")));
        $this->assertFalse($this->app->resolved(Client::class), 'No client was built.');
    }

    /** FRM-3: an API that cannot be reached is a catalog with nothing in it, through the real core. */
    public function testAnUnreachableApiIsPlainLaravelToo(): void
    {
        config()->set(['langsys.api_key' => 'test-key', 'langsys.project_id' => 'test-project', 'langsys.api_url' => self::UNREACHABLE_API]);
        $plain = new Translator($this->app['translation.loader'], 'en');
        $plain->setFallback('en');
        $reported = $this->_reported();

        foreach ([['Hello :name', ['name' => 'Ana']], ['messages.welcome', ['name' => 'Ana']], ['Save', []]] as [$key, $replace]) {
            $this->assertSame($plain->get($key, $replace), __($key, $replace, 'es-ES'), $key);
        }

        $this->assertSame($plain->choice('messages.apples', 3), trans_choice('messages.apples', 3, [], 'es-ES'));
        $this->assertTrue($this->app->resolved(Client::class), 'Control: the real client was built and asked.');
        $this->assertSame([], $reported->getArrayCopy(), 'Nothing reported.');
    }

    public function testAPageAndAFailedFormStillServe(): void
    {
        $reported = $this->_reported();

        $this->get('/pricing?locale=es-ES')->assertOk()->assertSee('<p>Hello Ana</p><p>Welcome back, Ana</p>', false);

        $response = $this->postJson('/api/cards', [])->assertStatus(422);
        $this->assertSame($response->json('errors.cc_number.0'), $response->json('langsys_errors.0.message'), 'The source, as with nothing in the catalog.');

        $this->assertFalse($this->app->resolved(Client::class));
        $this->assertSame([], $reported->getArrayCopy(), 'Nothing reported.');
    }

    /**
     * F4: with no key, `langsys:sync` registers nothing and says what it needs, and `--dry-run` plans
     * offline: every phrase counts as new, and `--strict` still fails on a call it cannot read as a
     * literal — the gate a CI job with no secrets holds.
     */
    public function testSyncChecksWithoutAKeyAndRegistersOnlyWithOne(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/sync/app']);

        $this->artisan('langsys:sync')
            ->expectsOutputToContain('langsys:sync needs LANGSYS_API_KEY and LANGSYS_PROJECT_ID to register')
            ->assertExitCode(1);

        $this->artisan('langsys:sync', ['--dry-run' => true])
            ->expectsOutputToContain('No key: nothing is compared with the catalog')
            ->expectsOutputToContain('OrderController.php:12: __() is called with something that is not a literal')
            ->assertExitCode(0);

        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])->assertExitCode(1);
        $this->assertFalse($this->app->resolved(Client::class), 'No client was built.');
    }

    public function testTheCauseIsReportedOncePerProcessAtDebug(): void
    {
        Log::spy();

        __('Hello :name', ['name' => 'Ana']);
        __('Save');

        Log::shouldHaveReceived('debug')->once()->withArgs(fn (string $message) => str_contains($message, 'LANGSYS_API_KEY'));
    }

    /** What Laravel's exception handler is asked to report from here on (Laravel 10 has no Exceptions::fake()). */
    private function _reported(): \ArrayObject
    {
        $reported = new \ArrayObject();
        $this->app->make(ExceptionHandler::class)->reportable(fn (\Throwable $e) => $reported->append($e::class . ': ' . $e->getMessage()));

        return $reported;
    }

}
