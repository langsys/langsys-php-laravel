<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\Translator;
use Langsys\Laravel\Facades\Langsys;
use Langsys\Laravel\Support\ClientState;
use Langsys\Laravel\Support\InertiaSsrProps;
use Langsys\Laravel\Translation\CatalogTranslator;
use Langsys\SDK\Client;
use Langsys\SDK\Exception\ConfigurationException;

/**
 * FRM-1 and FRM-3: installing the package never breaks the app. With no API key — an app's own tests, CI, a
 * fresh checkout — the catalog is empty by definition, so every translate call is exactly what
 * plain Laravel returns, the pages and forms that use this package's middleware still serve, and
 * no client is ever built. The cause is reported once per process, at debug (FRM-3).
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
        $router->middleware(['web', 'langsys.locale'])->get('/ping', fn () => response()->json(['ok' => true]));
        $router->middleware('api')->get('/api/forbidden', fn () => abort(403, __('Save')));
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
        $this->assertSame($plain->get('messages.welcome', ['name' => 'Ana']), t('messages.welcome', ['name' => 'Ana']));
        $this->assertSame($plain->get('messages.welcome', ['name' => 'Ana']), trim(Blade::render("@t('messages.welcome', ['name' => 'Ana'])")));
        $this->assertFalse($this->app->resolved(Client::class), 'No client was built.');
    }

    /** No seed, so the JS SDK fetches as usual; with no key, asking for one is not an error to report. */
    public function testTheInertiaSeedIsEmptyAndNothingIsReported(): void
    {
        $reported = $this->_reported();

        $this->assertSame(['langsys' => ['initialTranslations' => null, 'initialTranslationsLocale' => null]], InertiaSsrProps::share('es-ES'));
        $this->assertFalse($this->app->resolved(Client::class), 'No client was built.');
        $this->assertSame([], $reported->getArrayCopy(), 'Nothing reported.');
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

    /** With no key a page is Laravel's own HTML: no value is marked, so an app's own assertions on its pages hold. */
    public function testAPageIsLaravelsOwnHtml(): void
    {
        $this->assertSame('<p>Hello Ana</p>', Blade::render('<p>Hello {{ $name }}</p>', ['name' => 'Ana']));
    }

    /**
     * WIRE-4: outside Laravel's translator the facade asks the core directly, and the core's client
     * without credentials renders the source with its values filled. Its one warning per process
     * goes to the PHP error log, kept out of this run's output.
     */
    public function testTheFacadeRendersTheSource(): void
    {
        $log = ini_set('error_log', '/dev/null');

        try {
            $this->assertSame('Hello Ana', Langsys::translate('Hello {name}', null, ['name' => 'Ana'], 'es-ES'));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    /** The core's reference lists are empty without a key, so a form that offers them still renders. */
    public function testTheFacadesReadsListNothing(): void
    {
        $log = ini_set('error_log', '/dev/null');

        try {
            $this->assertSame([], Langsys::getCurrencies());
            $this->assertSame([], Langsys::getCountries('es-ES'));
            $this->assertSame([], Langsys::getDialCodes());
            $this->assertSame([], Langsys::getLocalesFormat('flat'));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    /**
     * A test's own fake client, bound with credentials it never sends, is asked even where the
     * config has none: the app's tests translate through it.
     */
    public function testABoundClientWithCredentialsIsAsked(): void
    {
        $fake = new \Langsys\Laravel\Tests\Fakes\FakeClient();
        $fake->seed('es-ES', '__uncategorized__', ['Pay now' => 'Pagar ahora']);
        $this->app->instance(Client::class, $fake);
        $this->app->setLocale('es-ES');

        $this->assertSame('Pagar ahora', __('Pay now'));
    }

    /**
     * A client the app built itself, through the facade, has no key either, so it changes nothing:
     * the pages, a failed form, a JSON error, the Inertia seed and Blade stay Laravel's own.
     */
    public function testAClientTheFacadeBuiltChangesNothing(): void
    {
        $log = ini_set('error_log', '/dev/null');

        try {
            Langsys::getCountries();
            $this->assertTrue($this->app->resolved(Client::class), 'Control: the facade built a client.');
            $reported = $this->_reported();

            $this->get('/pricing?locale=es-ES')->assertOk()->assertSee('<p>Hello Ana</p><p>Welcome back, Ana</p>', false);
            $this->postJson('/api/cards', [])->assertStatus(422);
            $this->getJson('/api/forbidden')->assertStatus(403)->assertJsonPath('message', 'Save changes');
            $this->assertSame(['langsys' => ['initialTranslations' => null, 'initialTranslationsLocale' => null]], InertiaSsrProps::share('es-ES'));
            $this->assertSame('<p>Hello Ana</p>', Blade::render('<p>Hello {{ $name }}</p>', ['name' => 'Ana']));
            $this->assertSame([], $reported->getArrayCopy(), 'Nothing reported.');
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    /**
     * WIRE-4: with `require_credentials` on, a missing key is an error, as production wants it.
     * Laravel still boots, so an image build or `config:cache` without secrets completes, and the
     * first use throws the core's exception, wherever it is: a translate call, the locale
     * middleware, the Inertia seed, a validator. None of them degrades it to the source.
     */
    public function testRequireCredentialsThrowsAtFirstUseNeverAtBoot(): void
    {
        $this->get('/ping')->assertOk();

        putenv('LANGSYS_REQUIRE_CREDENTIALS=true');

        try {
            $this->refreshApplication();
        } finally {
            putenv('LANGSYS_REQUIRE_CREDENTIALS');
        }

        $this->assertTrue(config('langsys.require_credentials'), 'Control: the option is on.');
        $this->assertFalse($this->app->resolved(Client::class), 'Booting built no client.');

        foreach ([
            '__()'       => fn () => __('Save'),
            'Inertia'    => fn () => InertiaSsrProps::share(),
            'validation' => fn () => Validator::make([], ['name' => 'required'])->fails(),
            'a read'     => fn () => Langsys::getCurrencies(),
        ] as $use => $call) {
            try {
                $call();
                $this->fail("{$use}: the first use throws.");
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('API key is required', $e->getMessage(), $use);
            }
        }

        $this->get('/ping')->assertStatus(500);
    }

    /** What Laravel's exception handler is asked to report from here on (Laravel 10 has no Exceptions::fake()). */
    private function _reported(): \ArrayObject
    {
        $reported = new \ArrayObject();
        $this->app->make(ExceptionHandler::class)->reportable(fn (\Throwable $e) => $reported->append($e::class . ': ' . $e->getMessage()));

        return $reported;
    }

}
