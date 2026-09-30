<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Langsys\Laravel\Tests\Fixtures\Http\InertiaMiddleware;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;

/**
 * MSG-12's shared fixture: the Inertia page object the next GET returns after a failed form
 * redirect, with Inertia's own middleware installed, so the framework's `errors` prop sits,
 * untouched, beside `langsys_errors`. The Vue binding vendors the file byte for byte and renders
 * the entries from it.
 *
 * The file is written by this test, never by hand. When what Laravel produces changes, the test
 * rewrites the file and fails, so the change is seen, committed, and sent to the Vue lane.
 */
class InertiaPageFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/inertia/failed-form-page.json';

    protected function getPackageProviders($app): array
    {
        return [InertiaServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Client::class, $this->offlineClient());
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', InertiaMiddleware::class])->post('/cards', fn (Request $request) => $request->validate([
            'cc_number' => 'required',
            'amount'    => 'numeric|max:100',
        ]));
        $router->middleware(['web', InertiaMiddleware::class])->get('/cards/new', fn () => Inertia::render('Cards/New'));
    }

    public function testTheFailedFormPageIsTheCommittedFixture(): void
    {
        $this->from('/cards/new')->post('/cards', ['amount' => 250])->assertRedirect('/cards/new');

        $page = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->get('/cards/new')->assertOk()->json();

        // What the fixture has to show before it is worth vendoring.
        $this->assertSame(['cc_number', 'amount'], array_keys($page['props']['errors']), "Inertia's own `errors` prop, untouched.");
        $this->assertSame(['required', 'max'], array_column($page['props']['langsys_errors'], 'code'));
        $this->assertSame(['max' => 100], $page['props']['langsys_errors'][1]['params']);

        $json = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $committed = is_file(self::FIXTURE) ? file_get_contents(self::FIXTURE) : null;

        if ($committed !== $json) {
            @mkdir(dirname(self::FIXTURE), 0777, true);
            file_put_contents(self::FIXTURE, $json);
        }

        $this->assertSame($committed ?? $json, $json, 'The Inertia page changed: the fixture is rewritten; commit it and tell the Vue lane.');
    }
}
