<?php

namespace Langsys\Laravel\Tests\Fixtures\Data;

use Illuminate\Contracts\Validation\Rule;

/** A rule object declaring its message through Laravel's `Rule` contract, `:attribute` and all. */
class UppercaseCode implements Rule
{
    public function passes($attribute, $value): bool
    {
        return is_string($value) && strtoupper($value) === $value;
    }

    public function message(): string
    {
        return 'The :attribute must be uppercase.';
    }
}
