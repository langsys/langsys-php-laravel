<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

use Langsys\SDK\Messages\HasAppMessageTemplate;

/** A base the app's errors extend: the contract is inherited, out of a per-file scan's sight. */
abstract class ApiError implements HasAppMessageTemplate
{
    protected static function source(string $sentence): string
    {
        return $sentence;
    }
}
