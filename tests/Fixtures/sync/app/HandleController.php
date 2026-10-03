<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

class HandleController
{
    public function store(HandleRequest $request)
    {
        return 'ok';
    }
}
