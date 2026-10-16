<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator as LaravelValidator;
use Langsys\SDK\Client;

/**
 * The off switch, for debugging: with `langsys.enabled` false this package installs nothing and
 * Laravel answers exactly as it would without it — its own translator, its own validator and
 * wording, a 422 body with nothing of ours, and a Langsys client that reads no lang file. The
 * assertions are about identity: anything else is this package deciding something while switched
 * off.
 */
class DisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.enabled', false);
        $app->useLangPath(__DIR__ . '/Fixtures/lang');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->post('/api/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
        $router->middleware('web')->post('/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
        $router->middleware(['web', 'langsys.locale', 'langsys.translate-page', 'langsys.flush'])->get('/page', fn () => '<html><body><p>Save</p></body></html>');
    }

    public function testLaravelsOwnTranslatorAnswers(): void
    {
        $this->assertSame(Translator::class, get_class($this->app['translator']));
        $this->assertSame('Welcome back, Ana', __('messages.welcome', ['name' => 'Ana']));
        $this->assertSame('Welcome back, Ana', t('messages.welcome', ['name' => 'Ana']), 't() is __() by another name.');
        $this->assertSame([], $this->fakeClient->asked, 'The client is never asked.');
    }

    public function testTheClientIsBuiltWithNoMigrationFiles(): void
    {
        $this->app->forgetInstance(Client::class);

        $this->assertNull($this->app->make(Client::class)->getLegacyKeys());
    }

    /** Off, the app's classes are not scanned for value sets: the scan loads every class it finds. */
    public function testTheAppsClassesAreNotScannedWhileOff(): void
    {
        $this->app->useAppPath(__DIR__ . '/Fixtures/Discovery/Off');
        $this->app->forgetInstance(Client::class);
        $this->app->make(Client::class);

        $this->assertFalse(class_exists(\Langsys\Laravel\Tests\Fixtures\Discovery\Off\Probe::class, false));

        // Control: on, building the client scans them.
        config()->set('langsys.enabled', true);
        $this->app->useAppPath(__DIR__ . '/Fixtures/Discovery/On');
        $this->app->forgetInstance(Client::class);
        $this->app->make(Client::class);

        $this->assertTrue(class_exists(\Langsys\Laravel\Tests\Fixtures\Discovery\On\Probe::class, false));
    }

    public function testLaravelBuildsItsOwnValidator(): void
    {
        $this->assertSame(LaravelValidator::class, get_class(Validator::make([], ['name' => 'required'])));

        $validator = Validator::make([], ['cc_number' => 'required'], [], ['cc_number' => 'card number']);
        $this->assertSame('The card number is needed.', $validator->errors()->first('cc_number'), "The app's own validation.php answers, as in Laravel.");
    }

    public function testTheJsonBodyCarriesNothingOfOurs(): void
    {
        $response = $this->postJson('/api/cards', []);

        $response->assertStatus(422);
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
    }

    /** The middleware an app put on its routes does nothing: Laravel's locale stands, nothing is persisted, the page is served as rendered. */
    public function testTheMiddlewareOnARouteDoesNothing(): void
    {
        config()->set('langsys.translate_response.enabled', true);
        $this->disableCookieEncryption();

        $response = $this->get('/page?locale=es-ES', ['Accept-Language' => 'es-ES']);

        $response->assertOk();
        $this->assertSame('<html><body><p>Save</p></body></html>', $response->getContent());
        $this->assertSame('en', app()->getLocale());
        $this->assertNull($response->getCookie('langsys_locale', false));
        $this->assertSame([], $this->fakeClient->translatedPages);
        $this->assertSame(0, $this->fakeClient->flushCalls);
    }

    /**
     * Switched off, the commands say so and do nothing: with no migration files the client has no
     * base-language lines, so a plan would register only part of the app.
     */
    public function testTheCommandsRegisterNothing(): void
    {
        foreach (['langsys:sync', 'langsys:sync --dry-run', 'langsys:messages --register'] as $command) {
            $this->artisan($command)
                ->expectsOutputToContain('Langsys is switched off (LANGSYS_ENABLED=false)')
                ->assertExitCode(0);
        }
    }

    /** Off, the facade's client registers nothing at runtime either, as it does on (FRM-2). */
    public function testTheFacadeRegistersNothing(): void
    {
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(\Langsys\Laravel\LangsysTranslator::class);
        \Langsys\Laravel\Facades\Langsys::clearResolvedInstances();
        $this->seedCatalog('es-es', ['UI' => []]);

        \Langsys\Laravel\Facades\Langsys::translate('Save', 'UI', [], 'es-ES');

        $this->assertSame([], $this->app->make(Client::class)->getPendingPhrases());
    }

    public function testARedirectFlashesNothingOfOurs(): void
    {
        $this->from('/cards/new')->post('/cards', [])->assertRedirect('/cards/new');

        $this->assertNull(session('langsys_errors'));
    }
}
