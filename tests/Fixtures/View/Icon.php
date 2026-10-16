<?php

namespace Langsys\Laravel\Tests\Fixtures\View;

use Illuminate\View\Component;

/** A class component an app registers itself, as an icon package does. */
class Icon extends Component
{
    public function render(): string
    {
        return '<svg aria-hidden="true"></svg>';
    }
}
