<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Langsys\SDK\Messages\HasAppMessageTemplate;

/** A Laravel validation rule that also says it is an app message: it stays a rule, listed per field. */
class PlainSlugRule implements ValidationRule, HasAppMessageTemplate
{
    public function template(): string
    {
        return 'Must be a slug.';
    }

    public function code(): string
    {
        return 'slug';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }
}
