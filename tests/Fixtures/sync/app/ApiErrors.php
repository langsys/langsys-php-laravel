<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

use Langsys\SDK\Messages\HasAppMessageTemplate;

/** An app's errors declared as a backed enum: one message per case, each case its own code. */
enum ApiErrors: string implements HasAppMessageTemplate
{
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';

    public function template(string $locale = 'en'): string
    {
        return match ($this) {
            self::NotFound  => __('That record does not exist.', [], $locale),
            self::Forbidden => __('You may not change this record.', [], $locale),
        };
    }

    public function code(): string
    {
        return $this->value;
    }
}
