<?php

namespace Langsys\Laravel\Tests\Messages\Fixtures;

use Illuminate\Contracts\Validation\Rule;
use RuntimeException;

/** A rule object that cannot say its message outside a request, as some packages' rules cannot. */
class Explodes implements Rule
{
    public function passes($attribute, $value): bool
    {
        return true;
    }

    public function message(): string
    {
        throw new RuntimeException('needs the request');
    }
}
