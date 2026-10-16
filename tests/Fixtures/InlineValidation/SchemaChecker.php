<?php

namespace Langsys\Laravel\Tests\Fixtures\InlineValidation;

/** A class with a `validate()` of its own, which is no Laravel validation. */
class SchemaChecker
{
    public function check(array $body): bool
    {
        return $this->validate($body, 'order');
    }

    private function validate(array $body, string $schema): bool
    {
        return $body !== [] && $schema !== '';
    }
}
