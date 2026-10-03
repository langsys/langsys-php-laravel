<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Langsys\SDK\Messages\HasAppMessageTemplate;
use Langsys\SDK\Messages\HasMessageTemplate;

/** A rule that is also an app message: it is listed per field, as a rule, and only so. */
class SlugRule implements ValidationRule, HasMessageTemplate, HasAppMessageTemplate
{
    public function template(): string
    {
        return 'The :attribute must be a slug.';
    }

    public function code(): string
    {
        return 'slug';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }
}
