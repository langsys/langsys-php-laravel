<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Langsys\SDK\Messages\HasMessageTemplate;

/** A rule object that states its sentence ahead of time: the limit is a marker, not a number in the phrase. */
class MaxWords implements ValidationRule, HasMessageTemplate
{
    public function __construct(public int $max)
    {
    }

    public function template(): string
    {
        return 'The :attribute may not be more than {max} words.';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (str_word_count((string) $value) > $this->max) {
            $fail("The :attribute may not be more than {$this->max} words.");
        }
    }
}
