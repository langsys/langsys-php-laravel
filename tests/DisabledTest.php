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
    }

    public function testLaravelsOwnTranslatorAnswers(): void
    {
        $this->assertSame(Translator::class, get_class($this->app['translator']));
        $this->assertSame('Welcome back, Ana', __('messages.welcome', ['name' => 'Ana']));
        $this->assertSame('Welcome back, Ana', t('messages.welcome', ['name' => 'Ana']), 't() is __() by another name.');
        $this->assertSame([], $this->fakeClient->queuedPhrases);
    }

    public function testTheClientIsBuiltWithNoMigrationFiles(): void
    {
        $this->app->forgetInstance(Client::class);

        $this->assertNull($this->app->make(Client::class)->getLegacyKeys());
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

    public function testARedirectFlashesNothingOfOurs(): void
    {
        $this->from('/cards/new')->post('/cards', [])->assertRedirect('/cards/new');

        $this->assertNull(session('langsys_errors'));
    }
}
