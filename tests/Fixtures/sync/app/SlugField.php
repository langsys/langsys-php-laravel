<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Langsys\SDK\Messages\HasMessageTemplate;

class SlugField implements ValidationRule, HasMessageTemplate
{
    public function template(): string
    {
        return FieldErrors::NotASlug->template();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }
}
