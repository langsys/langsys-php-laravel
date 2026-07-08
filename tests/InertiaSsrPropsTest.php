<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Support\InertiaSsrProps;

class InertiaSsrPropsTest extends TestCase
{
    public function testBuildsTheJsSdkSeedingShape(): void
    {
        $this->fakeClient->seed('es-ES', 'UI', ['Save' => 'Guardar']);
        $this->app->setLocale('es-ES');

        $props = InertiaSsrProps::share();

        $this->assertSame([
            'langsys' => [
                'initialTranslations'       => ['UI' => ['Save' => 'Guardar']],
                'initialTranslationsLocale' => 'es-ES',
            ],
        ], $props);
    }

    public function testExplicitLocaleOverridesTheAppLocale(): void
    {
        $this->fakeClient->seed('fr-FR', 'UI', ['Save' => 'Enregistrer']);
        $this->app->setLocale('es-ES');

        $props = InertiaSsrProps::share('fr-FR');

        $this->assertSame('fr-FR', $props['langsys']['initialTranslationsLocale']);
        $this->assertSame(['UI' => ['Save' => 'Enregistrer']], $props['langsys']['initialTranslations']);
    }

    public function testLocaleIsCanonicalizedForTheJsHandoff(): void
    {
        $this->fakeClient->seed('pt-BR', 'UI', ['Save' => 'Salvar']);
        $this->app->setLocale('pt_br');

        $props = InertiaSsrProps::share();

        $this->assertSame('pt-BR', $props['langsys']['initialTranslationsLocale']);
        $this->assertSame(['UI' => ['Save' => 'Salvar']], $props['langsys']['initialTranslations']);
    }
}
