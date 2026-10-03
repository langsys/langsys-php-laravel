<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

use Langsys\SDK\Messages\HasAppMessageTemplate;

/** An API error the app defines itself, its sentence written through `__()` as apps do. */
final class QuotaExceeded implements HasAppMessageTemplate
{
    public function __construct(public int $limit)
    {
    }

    public function template(): string
    {
        return __('You have used all {limit} requests this month.');
    }

    public function code(): string
    {
        return 'quota_exceeded';
    }
}
