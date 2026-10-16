<?php

namespace Laravel\Octane\Events;

/* Stand-in for Octane's event at the end of a tick, which runs in a sandbox of its own, as a request does. */
if (!class_exists(TickTerminated::class, false)) {
    class TickTerminated
    {
        public function __construct(public $app = null, public $sandbox = null)
        {
        }
    }
}
