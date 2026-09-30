<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Tests\Fixtures\GreeterComponent;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

/**
 * Proves Livewire "support" is real, not just architectural: `__()` resolves in
 * the active locale inside a Livewire component, interpolation runs, and — the
 * load-bearing claim — a phrase that only surfaces on a Livewire interaction is
 * discovered (queued) and then drained by the flush middleware. Locale
 * resolution on the Livewire update route is the DetectLocale cookie path,
 * covered by DetectLocaleTest; here the app locale is set directly to stand in
 * for what that middleware does on the initial page load.
 */
class LivewireSupportTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            \Langsys\Laravel\LangsysServiceProvider::class,
        ];
    }

    public function testTranslatesAndInterpolatesInsideALivewireComponent(): void
    {
        $this->app->setLocale('es-ES');
        $this->fakeClient->seed('es-ES', '__uncategorized__', [
            'Welcome back, {name}' => 'Bienvenida de nuevo, {name}',
        ]);

        Livewire::test(GreeterComponent::class)
            ->assertSee('Bienvenida de nuevo, Sarah');
    }

    public function testInterpolationTracksAReactivePropertyAcrossUpdates(): void
    {
        $this->app->setLocale('es-ES');
        $this->fakeClient->seed('es-ES', '__uncategorized__', [
            'Welcome back, {name}' => 'Bienvenida de nuevo, {name}',
        ]);

        Livewire::test(GreeterComponent::class)
            ->assertSee('Bienvenida de nuevo, Sarah')
            ->set('name', 'Diego')
            ->assertSee('Bienvenida de nuevo, Diego');
    }

    /** FRM-2: a phrase an interaction renders is served, and registers nothing at runtime; `langsys:sync` registers it. */
    public function testAPhraseAnInteractionRendersRegistersNothingAtRuntime(): void
    {
        $this->app->setLocale('es-ES');
        $this->fakeClient->seed('es-ES', '__uncategorized__', [
            'Welcome back, {name}' => 'Bienvenida de nuevo, {name}',
        ]);

        Livewire::test(GreeterComponent::class)->call('expand')->assertSee('Here are your latest updates');

        $this->assertSame([], $this->fakeClient->getPendingPhrases());
        $this->assertSame([], $this->fakeClient->queuedPhrases);
    }
}
