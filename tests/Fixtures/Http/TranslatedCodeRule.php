<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Contracts\Validation\Rule;

/** A rule object whose message is the app's own `__()` line, as many apps write one. */
class TranslatedCodeRule implements Rule
{
    public function passes($attribute, $value): bool
    {
        return false;
    }

    public function message(): string
    {
        return __('Enter a valid code.');
    }
}
