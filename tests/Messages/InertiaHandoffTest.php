<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;

/**
 * MSG-12: a framework that redirects after a failure has to hand the entries to the page it
 * redirects to, or they are lost and the next page shows nothing translated. Inertia is that
 * framework here: the entries ride the session across the redirect and arrive as a page prop, for
 * the destination page's JS SDK to render through MSG-5.
 */
class InertiaHandoffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InertiaServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.localization', 'migrate');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Client::class, $this->offlineClient());
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->post('/cards', fn (Request $request) => $request->validate(['cc_number' => 'required']));
        $router->middleware('web')->get('/cards/new', fn () => Inertia::render('Cards/New'));
    }

    private function _inertiaGet(string $uri)
    {
        return $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->get($uri);
    }

    public function testTheEntriesReachTheNextPageAsAProp(): void
    {
        $this->from('/cards/new')->post('/cards', [])->assertRedirect('/cards/new');

        $this->_inertiaGet('/cards/new')
            ->assertOk()
            ->assertJsonPath('props.langsys_errors.0.code', 'required')
            ->assertJsonPath('props.langsys_errors.0.field', 'cc_number')
            ->assertJsonPath('props.langsys_errors.0.template', 'The cc number field is required.')
            ->assertJsonPath('props.langsys_errors.0.message', 'The cc number field is required.');
    }

    /** The prop is the entries, not a translation: the client renders that itself (MSG-5). */
    public function testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation(): void
    {
        $this->app->instance(Client::class, $this->offlineClient([
            'es-es' => ['Errors' => ['The cc number field is required.' => 'El número de tarjeta es obligatorio.']],
        ]));
        $this->app->setLocale('es-ES');

        $this->from('/cards/new')->post('/cards', [])->assertRedirect('/cards/new');

        $this->_inertiaGet('/cards/new')->assertJsonPath('props.langsys_errors.0.message', 'The cc number field is required.');
    }

    /** A page reached without a failure carries no prop of ours at all. */
    public function testAPageWithNoFailureCarriesNothing(): void
    {
        $this->_inertiaGet('/cards/new')->assertOk()->assertJsonMissingPath('props.langsys_errors');
    }
}
