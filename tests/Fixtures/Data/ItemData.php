<?php

namespace Langsys\Laravel\Tests\Fixtures\Data;

use Spatie\LaravelData\Data;

class ItemData extends Data
{
    public function __construct(public string $phrase)
    {
    }
}
