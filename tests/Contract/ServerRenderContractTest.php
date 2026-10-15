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

        // VAR-5 on the page walk: a Blade sentence with a user's name in it.
        $router->middleware(['web', 'langsys.locale', 'langsys.translate-page', 'langsys.flush'])->get('/walk/greeting', fn () => Blade::render('<html><body><p>Hello {{ $name }}, welcome back</p></body></html>', ['name' => request('name')]));

        // TOK-1 on the page walk: a value Blade prints inside an element its author excluded, in a phrase host.
        $router->middleware(['web', 'langsys.locale', 'langsys.translate-page', 'langsys.flush'])->get('/walk/excluded', fn () => Blade::render('<html><body><p data-langsys-phrase>Welcome to <b translate="no">{{ $brand }}</b> today</p></body></html>', ['brand' => request('brand')]));

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
     * VAR-1 and VAR-5 on the server: Blade marks the name it prints, so two users' pages register
     * one phrase, with the placeholder, and neither user's name reaches the catalog.
     */
    public function testTwoUsersPagesRegisterOnePhraseWithThePlaceholder(): void
    {
        $this->seedProject(['wk' => ['type' => 'write']], $this->_catalog());
        $this->useKey('wk');

        $this->get('/walk/greeting?locale=es-ES&name=Ana')->assertOk();
        $this->useKey('wk');
        $this->get('/walk/greeting?locale=es-ES&name=Bo')->assertOk();

        $phrases = array_column($this->registeredPhrases(), 1);
        $this->assertSame(1, count(array_keys($phrases, 'Hello {name}, welcome back')), 'One phrase for every user.');
        $this->assertSame([], array_values(array_filter($phrases, fn (string $p) => str_contains($p, 'Ana') || str_contains($p, 'Bo,'))));
    }

    /**
     * TOK-1 with VAR-5: a value Blade marks inside a `translate="no"` element never enters the
     * phrase. The page registers one phrase with an empty pair where the element sits and shows the
     * brand; translated, the element renders from source, so each page shows its own brand.
     */
    public function testAValuePrintedInsideAnExcludedElementIsNeverAPhrase(): void
    {
        $this->seedProject(['wk' => ['type' => 'write']]);
        $this->useKey('wk');

        $this->get('/walk/excluded?locale=es-ES&brand=Acme')->assertOk()
            ->assertSee('<p data-langsys-phrase>Welcome to <b translate="no"><!--ls:brand-->Acme<!--/ls--></b> today</p>', false);
        $this->assertSame([[null, 'Welcome to {m0o}{m0c} today']], $this->registeredPhrases(), 'One phrase, and the brand is not in it.');

        $this->seedProject(['wk' => ['type' => 'write']], ['phrases' => [['category' => null, 'phrase' => 'Welcome to {m0o}{m0c} today', 'translations' => ['es-es' => 'Bienvenido a {m0o}{m0c} hoy']]]]);
        $this->useKey('wk');

        $this->get('/walk/excluded?locale=es-ES&brand=Zeta')->assertOk()
            ->assertSee('<p data-langsys-phrase>Bienvenido a <b translate="no"><!--ls:brand-->Zeta<!--/ls--></b> hoy</p>', false);
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
