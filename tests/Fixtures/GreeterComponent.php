<?php

namespace Langsys\Laravel\Tests\Fixtures;

use Livewire\Component;

/**
 * A minimal Livewire component exercising the Langsys integration inside the
 * Livewire lifecycle: `@t` in the inline Blade view (with interpolation), and a
 * second phrase that only appears after an interaction — used to prove token
 * discovery happens on a Livewire UPDATE, not just the initial page render.
 */
class GreeterComponent extends Component
{
    public string $name = 'Sarah';

    public bool $expanded = false;

    public function expand(): void
    {
        $this->expanded = true;
    }

    public function render(): string
    {
        return <<<'blade'
        <div>
            <h1>@t('Welcome back, {name}', 'Livewire', ['name' => $name])</h1>
            @if ($expanded)
                <p>@t('Here are your latest updates', 'Livewire')</p>
            @endif
        </div>
        blade;
    }
}
