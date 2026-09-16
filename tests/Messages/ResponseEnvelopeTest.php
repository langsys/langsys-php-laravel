<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Http\Request;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;

/**
 * MSG-1: the entry's four pieces are fixed, the envelope around them is the application's. Laravel's
 * 422 body keeps its shape — `message` and `errors`, in the source language — and the entries sit
 * beside it under a configured key. A form that redirects carries them in the session instead, so
 * the next page can render them.
 */
class ResponseEnvelopeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.localization', 'migrate');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Client::class, $this->offlineClient(['es-es' => ['Errors' => ['Another sentence.' => 'Otra frase.']]]));
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->post('/api/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
        $router->middleware('web')->post('/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
    }

    public function testTheJsonBodyKeepsLaravelsShapeAndCarriesTheEntriesBesideIt(): void
    {
        $response = $this->postJson('/api/cards', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['cc_number'], 'langsys_errors'])
            ->assertJsonPath('errors.cc_number.0', 'The cc number field is required.')
            ->assertJsonPath('langsys_errors.0.code', 'required')
            ->assertJsonPath('langsys_errors.0.field', 'cc_number')
            ->assertJsonPath('langsys_errors.0.template', 'The cc number field is required.')
            ->assertJsonPath('langsys_errors.0.message', 'The cc number field is required.');
    }

    public function testTheKeyIsConfigurable(): void
    {
        config()->set('langsys.messages.response_key', 'errors_detail');

        $body = $this->postJson('/api/cards', [])->assertStatus(422)->json();

        $this->assertArrayHasKey('errors_detail', $body);
        $this->assertArrayNotHasKey('langsys_errors', $body);
    }

    public function testARedirectingFormCarriesTheEntriesInTheSession(): void
    {
        $response = $this->from('/cards/new')->post('/cards', []);

        $response->assertRedirect('/cards/new')->assertSessionHasErrors('cc_number');
        $this->assertSame(
            [['field' => 'cc_number', 'code' => 'required', 'message' => 'The cc number field is required.', 'template' => 'The cc number field is required.']],
            session('langsys_errors')
        );
    }

    /** A response that did not fail validation is left exactly as it was. */
    public function testAnOrdinaryResponseIsUntouched(): void
    {
        $body = $this->postJson('/api/cards', ['cc_number' => '4242'])->assertOk()->json();

        $this->assertSame(['cc_number' => '4242'], $body, 'A response that did not fail carries no key of ours, not even an empty one.');
    }
}
