<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Http\Request;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Exception\ApiException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The middleware's contract is WHETHER to call translatePage() and WHAT to hand
 * it — never what inside the HTML gets translated, which is upstream's. These
 * tests assert on the decision and on what the SDK received, not on DOM
 * walking.
 */
class TranslateResponseTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['langsys.translate-page'])->group(function ($router) {
            $router->get('/page', fn () => response('<p>Save</p>', 200, ['Content-Type' => 'text/html']));
            $router->get('/admin/page', fn () => response('<p>Save</p>', 200, ['Content-Type' => 'text/html']));
            $router->get('/api/data', fn () => response()->json(['label' => 'Save']));
            $router->get('/go', fn () => redirect('/page'));
            $router->get('/download', fn () => new StreamedResponse(
                fn () => print('<p>Save</p>'),
                200,
                ['Content-Type' => 'text/html']
            ));
            $router->get('/empty', fn () => response('   ', 200, ['Content-Type' => 'text/html']));
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.translate_response.enabled', true);
    }

    public function testTranslatesAnHtmlResponse(): void
    {
        $this->get('/page')->assertOk()->assertSee('Guardar', false);

        $this->assertCount(1, $this->fakeClient->translatedPages);
        $this->assertSame('<p>Save</p>', $this->fakeClient->translatedPages[0]['html']);
    }

    public function testDoesNothingWhenDisabled(): void
    {
        config()->set('langsys.translate_response.enabled', false);

        $this->get('/page')->assertOk()->assertSee('Save', false);

        $this->assertSame([], $this->fakeClient->translatedPages);
    }

    /**
     * The content-type guard is what keeps every Livewire and Inertia XHR
     * round-trip out, without this middleware knowing those libraries exist.
     */
    public function testSkipsJsonResponses(): void
    {
        $this->get('/api/data')->assertOk()->assertJson(['label' => 'Save']);

        $this->assertSame([], $this->fakeClient->translatedPages);
    }

    public function testSkipsRedirects(): void
    {
        $this->get('/go')->assertRedirect('/page');

        $this->assertSame([], $this->fakeClient->translatedPages);
    }

    /** getContent() on a streamed response is false, and reading it would drain the stream. */
    public function testSkipsStreamedResponses(): void
    {
        $this->get('/download')->assertOk();

        $this->assertSame([], $this->fakeClient->translatedPages);
    }

    public function testSkipsBlankBodies(): void
    {
        $this->get('/empty')->assertOk();

        $this->assertSame([], $this->fakeClient->translatedPages);
    }

    public function testExceptPathsAreExcluded(): void
    {
        config()->set('langsys.translate_response.except', ['admin/*']);

        $this->get('/admin/page')->assertOk()->assertSee('Save', false);
        $this->get('/page')->assertOk()->assertSee('Guardar', false);

        $this->assertCount(1, $this->fakeClient->translatedPages);
    }

    public function testOnlyPathsRestrictScope(): void
    {
        config()->set('langsys.translate_response.only', ['admin/*']);

        $this->get('/page')->assertOk()->assertSee('Save', false);
        $this->get('/admin/page')->assertOk()->assertSee('Guardar', false);

        $this->assertCount(1, $this->fakeClient->translatedPages);
    }

    /** `except` wins, so a broad include can be carved out. */
    public function testExceptWinsOverOnly(): void
    {
        config()->set('langsys.translate_response.only', ['*']);
        config()->set('langsys.translate_response.except', ['admin/*']);

        $this->get('/admin/page')->assertOk()->assertSee('Save', false);

        $this->assertSame([], $this->fakeClient->translatedPages);
    }

    /**
     * translatePage() reads the locale off the client rather than taking it as
     * an argument, and returns the HTML untouched when it is null. The
     * middleware must set it from the app locale — the same value
     * LangsysTranslator uses — so the two can never disagree about which
     * language a page is in.
     */
    public function testSetsTheClientLocaleFromTheAppLocale(): void
    {
        $this->app->setLocale('es-ES');

        $this->get('/page')->assertOk();

        $this->assertSame('es-es', $this->fakeClient->translatedPages[0]['locale']);
    }

    public function testPassesTheConfiguredCategory(): void
    {
        config()->set('langsys.translate_response.category', 'Marketing');

        $this->get('/page')->assertOk();

        $this->assertSame('Marketing', $this->fakeClient->translatedPages[0]['category']);
    }

    /**
     * An unreachable API must never take the page down — the invariant that
     * governs every lookup path in this package. The untranslated page is
     * served intact rather than blanked.
     */
    public function testServesTheUntranslatedPageWhenTheApiFails(): void
    {
        $this->app->instance(\Langsys\SDK\Client::class, new class extends FakeClient {
            public function translatePage($html, $category = null, array $selectorCategories = [], array $params = [])
            {
                throw new ApiException('Service unavailable', 503);
            }
        });

        $this->get('/page')->assertOk()->assertSee('Save', false);
    }

    /** A client returning nothing usable must not blank the response body. */
    public function testDoesNotBlankThePageWhenTranslationReturnsEmpty(): void
    {
        $this->app->instance(\Langsys\SDK\Client::class, new class extends FakeClient {
            public function translatePage($html, $category = null, array $selectorCategories = [], array $params = [])
            {
                return '';
            }
        });

        $this->get('/page')->assertOk()->assertSee('Save', false);
    }

    public function testCacheIsDisabledByDefaultSoEveryRequestIsTranslated(): void
    {
        $this->get('/page')->assertOk();
        $this->get('/page')->assertOk();

        $this->assertCount(2, $this->fakeClient->translatedPages);
    }

    public function testCachedPagesSkipTheSdkOnRepeatRequests(): void
    {
        config()->set('langsys.translate_response.cache.enabled', true);

        $this->get('/page')->assertOk()->assertSee('Guardar', false);
        $this->get('/page')->assertOk()->assertSee('Guardar', false);

        $this->assertCount(1, $this->fakeClient->translatedPages, 'The second request should be served from cache.');
    }

    /** Keyed by source HTML, so one locale's translation can never be served for another. */
    public function testCacheIsKeyedByLocale(): void
    {
        config()->set('langsys.translate_response.cache.enabled', true);

        $this->app->setLocale('es-ES');
        $this->get('/page')->assertOk();

        $this->app->setLocale('fr-FR');
        $this->get('/page')->assertOk();

        $this->assertCount(2, $this->fakeClient->translatedPages);
        $this->assertSame('fr-fr', $this->fakeClient->translatedPages[1]['locale']);
    }

    public function testMiddlewareIsNotAppliedGlobally(): void
    {
        // Registered only as an alias — a route without it must be untouched,
        // because automatic mode must never switch itself on for a project
        // that tags with @t.
        $this->app['router']->get('/untouched', fn () => response('<p>Save</p>', 200, ['Content-Type' => 'text/html']));

        $this->get('/untouched')->assertOk()->assertSee('Save', false);

        $this->assertSame([], $this->fakeClient->translatedPages);
    }
}
