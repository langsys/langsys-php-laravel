<?php

namespace Langsys\Laravel\Tests\Messages\Fixtures;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TwoFailures implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $fail('The :attribute is too plain.');
        $fail('The :attribute is too short to be safe.');
    }
}
