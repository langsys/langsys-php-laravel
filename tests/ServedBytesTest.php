<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Langsys\Laravel\Tests\Fakes\RecordingClient;
use Langsys\SDK\Client;

/**
 * SRV-1 and SRV-5 on the real SDK, through the route an application renders:
 * the locale middleware, Blade and `@t`. Asserted on the served bytes and on
 * the SDK's own registration queue — never on FakeClient's stand-in lookup.
 */
class ServedBytesTest extends TestCase
{
    private RecordingClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->offlineClient(['it-it' => ['__uncategorized__' => ['Pricing' => 'Prezzi']]]);
        $this->client->serveProject(['base_locale' => 'en-us', 'target_locales' => ['it-it']]);
        $this->app->instance(Client::class, $this->client);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.translate_response.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'langsys.locale'])->get('/pricing', fn () => Blade::render(
            "<html><body><h1>{{ __('Pricing') }}</h1><p>{{ __('Talk to sales') }}</p></body></html>"
        ));

        $router->middleware(['web', 'langsys.locale', 'langsys.translate-page'])->get('/walk', fn () => '<html><body>'
            . str_repeat('<section><div><p>Talk to sales</p></div></section>', 8) . '</body></html>');
    }

    /**
     * The request locale's translation is in the response, and a phrase the catalog lacks is
     * served in the base language — which separates "translated correctly" from "rendered a
     * catalog that happened to be complete". The page, rendered in a non-base locale, is marked
     * resolved (GATE-10).
     */
    public function testTheServedBytesCarryTheRequestLocalesTranslations(): void
    {
        $this->get('/pricing?locale=it-IT')
            ->assertOk()
            ->assertSee('<html data-ls-resolved="it-it"><body><h1>Prezzi</h1><p>Talk to sales</p></body></html>', false);
    }

    /**
     * SRV-5, once per subtree, on the page walk, which still collects what it meets: one phrase
     * met eight times across nested elements is one registration. Asserted as a count, because
     * identical copies are exactly what a set would hide.
     */
    public function testAMissTheWalkMeetsManyTimesIsQueuedOnce(): void
    {
        $this->get('/walk?locale=it-IT')->assertOk();

        $this->assertSame(['Talk to sales'], array_values(array_column($this->client->getPendingPhrases(), 'phrase')));
    }
}
