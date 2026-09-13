<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Langsys\Laravel\Support\InertiaSsrProps;
use Langsys\SDK\Client;

class InertiaSsrPropsTest extends TestCase
{
    public function testBuildsTheJsSdkSeedingShape(): void
    {
        $this->fakeClient->seed('es-ES', 'UI', ['Save' => 'Guardar']);
        $this->app->setLocale('es-ES');

        $this->assertSame([
            'langsys' => [
                'initialTranslations'       => ['UI' => ['Save' => 'Guardar']],
                'initialTranslationsLocale' => 'es-es',
            ],
        ], InertiaSsrProps::share());
    }

    public function testExplicitLocaleOverridesTheAppLocale(): void
    {
        $this->fakeClient->seed('fr-FR', 'UI', ['Save' => 'Enregistrer']);
        $this->app->setLocale('es-ES');

        $props = InertiaSsrProps::share('fr-FR');

        $this->assertSame('fr-fr', $props['langsys']['initialTranslationsLocale']);
        $this->assertSame(['UI' => ['Save' => 'Enregistrer']], $props['langsys']['initialTranslations']);
    }

    /**
     * WIRE-3: one locale form toward every SDK. The PHP SDK keys its catalog by
     * lowercase `xx-yy` and the JS SDK canonicalizes whatever it is handed to
     * the same form, so any spelling in Laravel's locale store reaches both as
     * one identity.
     */
    public function testEveryHostSpellingHandsTheSdkForm(): void
    {
        $this->fakeClient->seed('pt-br', 'UI', ['Save' => 'Salvar']);

        foreach (['pt-br', 'pt-BR', 'pt_BR', 'PT-br'] as $hostLocale) {
            $this->app->setLocale($hostLocale);

            $props = InertiaSsrProps::share();

            $this->assertSame('pt-br', $props['langsys']['initialTranslationsLocale'], "Host locale {$hostLocale}");
            $this->assertSame(['UI' => ['Save' => 'Salvar']], $props['langsys']['initialTranslations'], "Host locale {$hostLocale}");
        }
    }

    /**
     * SRV-4, the server's half: hand the client the catalog this request
     * rendered with. On the real SDK the render and the hand-off both read its
     * request-scoped memory, so the shared cache moving on between them must
     * not change what the client is handed. A hand-off that re-read the cache
     * would seed the client with a catalog the served HTML never used, and the
     * first client render would disagree with the server's.
     */
    public function testHandsTheClientTheCatalogThisRequestRenderedWith(): void
    {
        $this->app->instance(Client::class, $this->offlineClient(['es-es' => ['UI' => ['Save' => 'Guardar']]]));
        $this->app->setLocale('es-ES');

        $served = Blade::render("@t('Save', 'UI')");
        $this->seedCatalog('es-es', ['UI' => ['Save' => 'Salvar']]);

        $this->assertSame('Guardar', $served, 'Control: the render must have read the seeded catalog.');
        $this->assertSame(['UI' => ['Save' => 'Guardar']], InertiaSsrProps::share()['langsys']['initialTranslations']);
    }

    /**
     * WIRE-4. On the real SDK getTranslations() throws when the API is
     * unreachable, and share() runs on every Inertia request. An outage hands
     * no seed — never an empty one, which the JS SDK would mark loaded and
     * never fetch past — instead of turning those pages into 500s.
     */
    public function testAnUnreachableApiHandsNoSeedInsteadOfThrowing(): void
    {
        $this->app->instance(Client::class, $this->offlineClient());
        $this->app->setLocale('es-ES');

        $this->assertSame(
            ['langsys' => ['initialTranslations' => null, 'initialTranslationsLocale' => null]],
            InertiaSsrProps::share()
        );
    }
}
