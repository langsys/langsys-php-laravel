<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

final class ProjectLimitReached extends ApiError
{
    public function __construct(public int $limit)
    {
    }

    public function template(string $locale = 'en'): string
    {
        return self::source(__('Your plan allows {limit} projects.', [], $locale));
    }

    public function code(): string
    {
        return 'project_limit';
    }
}
