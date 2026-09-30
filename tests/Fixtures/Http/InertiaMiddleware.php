<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

/** An application's HandleInertiaRequests: on a route, it makes the route an Inertia page. */
class InertiaMiddleware extends \Inertia\Middleware
{
    protected $rootView = 'app';
}
