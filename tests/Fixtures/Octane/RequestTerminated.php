<?php

namespace Laravel\Octane\Events;

/*
 * Stand-in for Octane's event. Octane is not installed here: it needs a
 * Swoole, RoadRunner or FrankenPHP server to run at all. The service provider
 * listens by class name and never reads the payload Octane attaches, so the
 * name is the whole contract under test.
 */
if (!class_exists(RequestTerminated::class, false)) {
    class RequestTerminated
    {
    }
}
