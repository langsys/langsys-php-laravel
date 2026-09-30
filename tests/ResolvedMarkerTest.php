<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Tests\Fixtures\Http\InertiaMiddleware;
use Langsys\SDK\Client;

/**
 * GATE-10 and FRM-4: a page Laravel renders in a locale other than the project's base holds text
 * already translated by `__()`, so its root is marked `data-ls-resolved`, and a browser SDK on it
 * never reads that text as source. The core decides when and writes the marker; nothing else in
 * the page changes.
 */
class ResolvedMarkerTest extends TestCase
{
    private const PAGE = "<!DOCTYPE html>\n<html lang=\"x\">\n<body><p>{{ __('Pay now') }}</p></body>\n</html>\n";

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Inertia\ServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $client = $this->offlineClient(['es-es' => ['__uncategorized__' => ['Pay now' => 'Pagar ahora']], 'en-us' => ['__uncategorized__' => []]]);
        $client->serveProject(['base_locale' => 'en-us', 'target_locales' => ['es-es']]);
        $this->app->instance(Client::class, $client);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => \Illuminate\Support\Facades\Blade::render(self::PAGE));
        $router->middleware(['web', InertiaMiddleware::class])->get('/spa', fn () => \Illuminate\Support\Facades\Blade::render(self::PAGE));
        $router->middleware('web')->get('/plain', fn () => '<html><body>No translation here</body></html>');
    }

    public function testAPageRenderedInANonBaseLocaleIsMarkedResolved(): void
    {
        $this->app->setLocale('es-ES');

        $html = $this->get('/page')->assertOk()->getContent();

        $this->assertSame("<!DOCTYPE html>\n<html lang=\"x\" data-ls-resolved=\"es-es\">\n<body><p>Pagar ahora</p></body>\n</html>\n", $html, 'The marker, and nothing else changed.');
    }

    public function testAPageInTheBaseLocaleStaysSource(): void
    {
        $this->app->setLocale('en-US');

        $this->assertStringNotContainsString('data-ls-resolved', $this->get('/page')->getContent());
    }

    /** An Inertia page is source, for its own SDK to translate (FRM-4). */
    public function testAnInertiaPageIsNotMarked(): void
    {
        $this->app->setLocale('es-ES');

        $this->assertStringNotContainsString('data-ls-resolved', $this->get('/spa')->getContent());
    }

    /** A request that translated nothing built no client, and is left alone: an app without credentials is never touched. */
    public function testAPageThatTranslatedNothingIsLeftAlone(): void
    {
        $this->app->setLocale('es-ES');
        $this->app->forgetInstance(Client::class);
        config()->set('langsys.api_key', null);

        $this->get('/plain')->assertOk()->assertSee('<html><body>No translation here</body></html>', false);
    }
}
