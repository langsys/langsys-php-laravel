<?php

namespace Laravel\Octane\Events;

/*
 * Stand-in for Octane's event, dispatched once a worker has booted its application and before it
 * serves a request: the application every request's sandbox is cloned from.
 */
if (!class_exists(WorkerStarting::class, false)) {
    class WorkerStarting
    {
        public function __construct(public $app = null)
        {
        }
    }
}
