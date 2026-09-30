<?php

namespace Langsys\Laravel\Tests\Fixtures\Data;

use Spatie\LaravelData\Data;

class AddressData extends Data
{
    public function __construct(public string $zip)
    {
    }

    public static function attributes(): array
    {
        return ['zip' => 'ZIP code'];
    }
}
