<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Contracts\Validation\Rule;

/** A rule object whose `__()` message names the field, which Laravel writes in once the line is translated. */
class UsedBeforeRule implements Rule
{
    public function passes($attribute, $value): bool
    {
        return false;
    }

    public function message(): string
    {
        return __('The :attribute was used before.');
    }
}
