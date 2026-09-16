<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Http\Request;
use Langsys\Laravel\Tests\TestCase;

/** In keep mode the 422 body is Laravel's, whole and unchanged: no entries, nothing flashed. */
class KeepModeEnvelopeTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->post('/api/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
        $router->middleware('web')->post('/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
    }

    public function testTheJsonBodyCarriesNothingOfOurs(): void
    {
        $response = $this->postJson('/api/cards', []);

        $response->assertStatus(422)->assertJsonMissing(['langsys_errors']);
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
    }

    public function testARedirectFlashesNothingOfOurs(): void
    {
        $this->from('/cards/new')->post('/cards', [])->assertRedirect('/cards/new');

        $this->assertNull(session('langsys_errors'));
    }
}
