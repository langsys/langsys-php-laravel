<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Tests\Fixtures\GreeterComponent;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

/**
 * Proves Livewire "support" is real, not just architectural: `__()` resolves in
 * the active locale inside a Livewire component, interpolation follows a
 * reactive property across updates, and a phrase that only surfaces on a
 * Livewire interaction registers nothing at runtime — `langsys:sync` registers
 * it (FRM-2). Locale resolution on the Livewire update route is the
 * DetectLocale cookie path, covered by DetectLocaleTest; here the app locale is
 * set directly to stand in for what that middleware does on the initial page
 * load.
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

    /**
     * FRM-2: a phrase an interaction renders is served, and registers nothing at runtime;
     * `langsys:sync` registers it. On the real core, built as the provider builds it, with a core
     * that registers at runtime as the control that the interaction's miss is seen.
     */
    public function testAPhraseAnInteractionRendersRegistersNothingAtRuntime(): void
    {
        $this->app->setLocale('es-ES');

        foreach ([false => [], true => ['Here are your latest updates']] as $registering => $pending) {
            $client = $this->offlineClient(['es-es' => ['__uncategorized__' => ['Welcome back, {name}' => 'Bienvenida de nuevo, {name}']]], ['runtime_registration' => (bool) $registering]);
            $this->app->instance(\Langsys\SDK\Client::class, $client);
            $this->app->forgetInstance(\Langsys\Laravel\LangsysTranslator::class);

            Livewire::test(GreeterComponent::class)->call('expand')->assertSee('Here are your latest updates');

            $this->assertSame($pending, array_values(array_column($client->getPendingPhrases(), 'phrase')), $registering ? 'Control: the miss is seen.' : 'Nothing registers.');
        }
    }
}
