<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

/** Sentences a rule reads: not an app message, so only its listed sentence covers its literal. */
enum FieldErrors
{
    case NotASlug;

    public function template(): string
    {
        return match ($this) {
            self::NotASlug => __('Use lowercase letters and dashes only.'),
        };
    }
}
