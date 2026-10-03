<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Http\Request;
use Langsys\Laravel\Tests\Fakes\RecordingClient;
use Langsys\Laravel\Tests\Fixtures\SetsAppLocale;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;

/**
 * FRM-5: in a JSON response, each entry's `message` is in the language the request negotiated —
 * Accept-Language with its quality values, matched to the closest locale the project serves — or
 * in the locale the app resolved; the source when nothing matched or nothing is translated. The
 * entry keeps `template`, `params` and `code`, the response names the language of `message` in
 * `Content-Language`, and it varies on Accept-Language when the SDK negotiated. Laravel's own
 * `message` and `errors` are left as Laravel wrote them.
 */
class NegotiatedMessageTest extends TestCase
{
    private const TEMPLATE = 'The card number field is required.';
    private const SPANISH = 'El número de tarjeta es obligatorio.';

    private RecordingClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->offlineClient(['es-es' => ['Errors' => [self::TEMPLATE => self::SPANISH], '__uncategorized__' => ['Not yours.' => 'No es tuyo.']], 'en-us' => ['Errors' => []]]);
        $this->client->serveProject(['base_locale' => 'en-us', 'target_locales' => ['es-es']]);
        $this->app->instance(Client::class, $this->client);
    }

    protected function defineRoutes($router): void
    {
        $validate = fn (Request $request) => $request->validate(['cc_number' => 'required'], [], ['cc_number' => 'card number']);

        $router->middleware('api')->post('/api/cards', $validate);
        $router->middleware(['api', SetsAppLocale::class])->post('/api/resolved/cards', $validate);
        $router->middleware(['api', 'langsys.locale'])->get('/api/orders/1', fn () => abort(403, __('Not yours.')));
        $router->middleware(['api', 'langsys.locale'])->get('/api/own', fn () => response()->json(['message' => 'Hi'], 200, ['Content-Language' => 'fr-fr']));
    }

    public function testTheMessageIsInTheNegotiatedLanguage(): void
    {
        $response = $this->postJson('/api/cards', [], ['Accept-Language' => 'es;q=0.9, en;q=0.8'])->assertStatus(422);

        $this->assertSame(self::SPANISH, $response->json('langsys_errors.0.message'));
        $this->assertSame(self::TEMPLATE, $response->json('langsys_errors.0.template'));
        $this->assertSame('required', $response->json('langsys_errors.0.code'));
        $this->assertSame('es-es', $response->headers->get('Content-Language'));
        $this->assertContains('Accept-Language', $response->headers->all('vary'));
    }

    /**
     * FRM-5 for every JSON message, not only a validation failure's: a 403 whose `message` `__()`
     * wrote in the negotiated language names that language; with no match it names the base locale.
     * A response that names its own language keeps it.
     */
    public function testAnyJsonMessageNamesTheLanguageItIsIn(): void
    {
        $response = $this->getJson('/api/orders/1', ['Accept-Language' => 'es;q=0.9, en;q=0.8'])->assertStatus(403);
        $this->assertSame('No es tuyo.', $response->json('message'));
        $this->assertSame('es-es', $response->headers->get('Content-Language'));
        $this->assertContains('Accept-Language', $response->headers->all('vary'));

        $this->assertSame('en-us', $this->getJson('/api/orders/1', ['Accept-Language' => 'ja'])->headers->get('Content-Language'));
        $this->assertSame('fr-fr', $this->getJson('/api/own', ['Accept-Language' => 'es'])->headers->get('Content-Language'));
    }

    /** Laravel's own body is Laravel's: its `message` and `errors` keep the source language. */
    public function testLaravelsOwnBodyIsUntouched(): void
    {
        $response = $this->postJson('/api/cards', [], ['Accept-Language' => 'es']);

        $this->assertSame(self::TEMPLATE, $response->json('message'));
        $this->assertSame([self::TEMPLATE], $response->json('errors.cc_number'));
    }

    public function testAnUnservedLanguageGetsTheSourceInTheBaseLocale(): void
    {
        $response = $this->postJson('/api/cards', [], ['Accept-Language' => 'ja']);

        $this->assertSame(self::TEMPLATE, $response->json('langsys_errors.0.message'));
        $this->assertSame('en-us', $response->headers->get('Content-Language'));
        $this->assertContains('Accept-Language', $response->headers->all('vary'), 'The header was read, and another could have matched.');
    }

    /** A locale the app resolved is served as it is, and the SDK varies on nothing it did not read. */
    public function testALocaleTheAppResolvedIsTheMessagesLanguage(): void
    {
        $response = $this->postJson('/api/resolved/cards', [], ['X-App-Locale' => 'es-ES', 'Accept-Language' => 'en']);

        $this->assertSame(self::SPANISH, $response->json('langsys_errors.0.message'));
        $this->assertSame('es-es', $response->headers->get('Content-Language'));
        $this->assertNotContains('Accept-Language', $response->headers->all('vary'));
    }
}
