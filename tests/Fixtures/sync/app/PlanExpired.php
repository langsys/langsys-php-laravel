<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

/** Its sentence in a constant: the call reads no literal, and its class is what covers it. */
final class PlanExpired extends ApiError
{
    private const SENTENCE = 'Your plan has expired.';

    public function template(string $locale = 'en'): string
    {
        return __(self::SENTENCE, [], $locale);
    }

    public function code(): string
    {
        return 'plan_expired';
    }
}
