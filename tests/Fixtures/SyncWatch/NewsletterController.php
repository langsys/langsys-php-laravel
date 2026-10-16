<?php

namespace Langsys\Laravel\Tests\Fixtures\SyncWatch;

class NewsletterController
{
    public function store(NewsletterRequest $request): array
    {
        return $request->validated();
    }
}
