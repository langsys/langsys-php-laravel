<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Contracts\Validation\Rule;

/** A rule object whose message Laravel files under another field. */
class OtherFieldRule implements Rule
{
    public function passes($attribute, $value): bool
    {
        return false;
    }

    public function message(): array
    {
        return ['other' => 'Pick another.'];
    }
}
