<?php

namespace Laravel\Octane\Events;

/*
 * Stand-in for Octane's event. Octane is not installed here: it needs a
 * Swoole, RoadRunner or FrankenPHP server to run at all. It carries what
 * Octane's does: the worker's application, and the sandbox, the clone of it
 * that served the request and holds whatever the request resolved.
 */
if (!class_exists(RequestTerminated::class, false)) {
    class RequestTerminated
    {
        public function __construct(public $app = null, public $sandbox = null)
        {
        }
    }
}
