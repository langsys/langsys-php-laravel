<?php

namespace Langsys\Laravel\Tests\Contract;

use Illuminate\Support\Facades\Blade;

/**
 * SRV-1, SRV-3 and SRV-6 against the contract fixture, on the route an application renders:
 * Laravel's locale middleware, Blade and `__()`, the flush after the response. What the server
 * accepted is read back; nothing asserts on what the SDK sent.
 */
class ServerRenderContractTest extends ContractTestCase
{
    private const PAGE = '<html><body><h1>{{ __(\'Pricing\') }}</h1><p>{{ __(\'Talk to sales\') }}</p></body></html>';

    private const PLAIN_PAGE = '<html><body><h1>Pricing</h1><p>Talk to sales</p></body></html>';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.translate_response.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'langsys.locale', 'langsys.flush'])->get('/pricing', fn () => Blade::render(self::PAGE));
        $router->middleware(['web', 'langsys.locale'])->get('/locale', fn () => app()->getLocale());

        // Automatic mode: the page walk, which still collects what it meets (SRV-3, GATE-7).
        $router->middleware(['web', 'langsys.locale', 'langsys.translate-page', 'langsys.flush'])->get('/walk', fn () => self::PLAIN_PAGE);

        // Renders, then changes the world so the double would now accept this key's write.
        $router->middleware(['web', 'langsys.locale', 'langsys.translate-page', 'langsys.flush'])->get('/walk/drift', function () {
            $this->seedProject(['ipk' => ['type' => 'ip_write', 'ip_allowlist' => ['127.0.0.1']]], $this->_catalog());

            return self::PLAIN_PAGE;
        });
    }

    private function _catalog(): array
    {
        return ['phrases' => [['category' => null, 'phrase' => 'Pricing', 'translations' => ['es-es' => 'Precios']]]];
    }

    /**
     * SRV-1 on the route an application renders — `__()` in Blade — FRM-2 and GATE-10 on the server:
     * the page carries the catalog's translation, its root is marked resolved against the base
     * locale the server reports, and serving it registers nothing, not even the miss.
     */
    public function testABladePageServesTheCatalogAndRegistersNothing(): void
    {
        $this->seedProject(['wk' => ['type' => 'write']], $this->_catalog());
        $this->useKey('wk');

        $this->get('/pricing?locale=es-ES')->assertOk()
            ->assertSee('<html data-ls-resolved="es-es"><body><h1>Precios</h1><p>Talk to sales</p></body></html>', false);

        $this->assertSame([[null, 'Pricing']], $this->registeredPhrases(), 'Only the seeded phrase: nothing registered at runtime.');
    }

    /** SRV-1 and SRV-3 on the page walk: the translation is served, and the miss is on the server after the response. */
    public function testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse(): void
    {
        $this->seedProject(['wk' => ['type' => 'write']], $this->_catalog());
        $this->useKey('wk');

        $this->get('/walk?locale=es-ES')->assertOk()->assertSee('Precios', false);

        $this->assertContains([null, 'Talk to sales'], $this->registeredPhrases());
    }

    /**
     * SRV-3 for a key that may not write, with the capability drifted after the SDK learned it:
     * the request leaves the double in a world that would accept this key's write, and still
     * nothing is registered, because this request's decision was made. The control, a request
     * that learns afresh in the drifted world, does register.
     */
    public function testAKeyThatMayNotWriteRegistersNothingEvenWhenTheWorldChanges(): void
    {
        $this->seedProject(['ipk' => ['type' => 'ip_write', 'ip_allowlist' => []]], $this->_catalog());
        $this->useKey('ipk');

        $this->get('/walk/drift?locale=es-ES')->assertOk();
        $this->assertNotContains([null, 'Talk to sales'], $this->registeredPhrases());

        $this->useKey('ipk');
        $this->get('/walk?locale=es-ES')->assertOk();
        $this->assertContains([null, 'Talk to sales'], $this->registeredPhrases(), 'Control: in the drifted world this key may write.');
    }

    /** SRV-6: candidates are validated against the locales the server says the project serves. */
    public function testTheRequestLocaleIsValidatedAgainstTheProjectsLocales(): void
    {
        $this->seedProject(['wk' => ['type' => 'write']], ['target_locales' => ['es-es', 'fr-fr']]);
        $this->useKey('wk');

        $served = $this->get('/locale', ['Accept-Language' => 'fr;q=0.9, en;q=0.8']);
        $served->assertSeeText('fr-FR');
        $this->assertContains('Accept-Language', $served->headers->all('vary'));

        $this->get('/locale', ['Accept-Language' => 'ja'])->assertSeeText('en-US');
        $this->get('/locale?locale=it-IT', ['Accept-Language' => 'es'])->assertSeeText('es-ES', "A query locale the project doesn't serve falls through.");
    }
}
