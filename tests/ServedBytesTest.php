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

        $this->client = $this->offlineClient(['it-it' => ['UI' => ['Pricing' => 'Prezzi']]]);
        $this->app->instance(Client::class, $this->client);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'langsys.locale'])->get('/pricing', fn () => Blade::render(
            "<h1>@t('Pricing', 'UI')</h1><p>@t('Talk to sales', 'UI')</p>"
        ));
    }

    /**
     * The request locale's translation is in the response, and a phrase the
     * catalog lacks is served in the base language AND queued as a miss —
     * which separates "translated correctly" from "rendered a catalog that
     * happened to be complete".
     */
    public function testTheServedBytesCarryTheRequestLocalesTranslations(): void
    {
        $this->get('/pricing?locale=it-IT')
            ->assertOk()
            ->assertSee('<h1>Prezzi</h1>', false)
            ->assertSee('<p>Talk to sales</p>', false);

        $queued = array_column($this->client->getPendingPhrases(), 'phrase');

        $this->assertContains('Talk to sales', $queued, 'Control: the miss must be queued, or nothing here shows discovery ran.');
        $this->assertNotContains('Pricing', $queued);
    }

    /**
     * SRV-5, once per subtree: one phrase rendered eight times across three
     * nested loops is one registration. Asserted as a count, because the
     * copies a re-entrant render produces are identical and a set hides them.
     */
    public function testAMissRenderedManyTimesIsQueuedOnce(): void
    {
        $this->app->setLocale('it-IT');

        Blade::render(<<<'BLADE'
            @foreach ([1, 2] as $a)
                @foreach ([1, 2] as $b)
                    @foreach ([1, 2] as $c)
                        @t('Talk to sales', 'UI')
                    @endforeach
                @endforeach
            @endforeach
            BLADE);

        $this->assertCount(1, $this->client->getPendingPhrases());
    }
}
