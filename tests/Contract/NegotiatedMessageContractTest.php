<?php

namespace Langsys\Laravel\Tests\Contract;

use Illuminate\Http\Request;

/**
 * FRM-5 against the contract fixture: a JSON validation failure carries each entry's `message` in
 * the language negotiated against the locales the server says the project serves, with the
 * template and params beside it, and names that language in `Content-Language`.
 */
class NegotiatedMessageContractTest extends ContractTestCase
{
    private const TEMPLATE = 'The card number field is required.';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->post('/api/cards', fn (Request $request) => $request->validate(['cc_number' => 'required'], [], ['cc_number' => 'card number']));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProject(['wk' => ['type' => 'write']], [
            'phrases' => [['category' => 'Errors', 'phrase' => self::TEMPLATE, 'translations' => ['es-es' => 'El número de tarjeta es obligatorio.']]],
        ]);
        $this->useKey('wk');
    }

    public function testTheMessageIsInTheLanguageTheServerServes(): void
    {
        $response = $this->postJson('/api/cards', [], ['Accept-Language' => 'es;q=0.9, en;q=0.8'])->assertStatus(422);

        $this->assertSame('El número de tarjeta es obligatorio.', $response->json('langsys_errors.0.message'));
        $this->assertSame(self::TEMPLATE, $response->json('langsys_errors.0.template'));
        $this->assertSame('es-es', $response->headers->get('Content-Language'));
        $this->assertContains('Accept-Language', $response->headers->all('vary'));
    }

    public function testAnUnservedLanguageGetsTheSourceInTheBaseLocale(): void
    {
        $response = $this->postJson('/api/cards', [], ['Accept-Language' => 'ja'])->assertStatus(422);

        $this->assertSame(self::TEMPLATE, $response->json('langsys_errors.0.message'));
        $this->assertSame('en-us', $response->headers->get('Content-Language'));
    }
}
