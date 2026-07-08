<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Http\Middleware\FlushPendingRegistrations;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Langsys\Laravel\Tests\Fixtures\GreeterComponent;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

/**
 * Proves Livewire "support" is real, not just architectural: `@t` resolves in
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
        $this->fakeClient->seed('es-ES', 'Livewire', [
            'Welcome back, {name}' => 'Bienvenida de nuevo, {name}',
        ]);

        Livewire::test(GreeterComponent::class)
            ->assertSee('Bienvenida de nuevo, Sarah');
    }

    public function testInterpolationTracksAReactivePropertyAcrossUpdates(): void
    {
        $this->app->setLocale('es-ES');
        $this->fakeClient->seed('es-ES', 'Livewire', [
            'Welcome back, {name}' => 'Bienvenida de nuevo, {name}',
        ]);

        Livewire::test(GreeterComponent::class)
            ->assertSee('Bienvenida de nuevo, Sarah')
            ->set('name', 'Diego')
            ->assertSee('Bienvenida de nuevo, Diego');
    }

    public function testPhraseSurfacedByAnInteractionIsDiscoveredThenFlushed(): void
    {
        $this->app->setLocale('es-ES');
        $this->fakeClient->seed('es-ES', 'Livewire', [
            'Welcome back, {name}' => 'Bienvenida de nuevo, {name}',
        ]);

        // The second phrase is untranslated and only rendered after expand().
        $component = Livewire::test(GreeterComponent::class);
        $this->assertSame([], $this->fakeClient->queuedPhrases);

        $component->call('expand')->assertSee('Here are your latest updates');

        // Token discovery fired during the Livewire update, not just page load.
        $this->assertContains(
            ['phrase' => 'Here are your latest updates', 'category' => 'Livewire'],
            $this->fakeClient->queuedPhrases
        );

        // The flush middleware drains what the update discovered.
        $middleware = $this->app->make(FlushPendingRegistrations::class);
        $middleware->terminate(new Request(), new Response());

        $this->assertSame(1, $this->fakeClient->flushCalls);
        $this->assertSame([], $this->fakeClient->queuedPhrases);
    }
}
