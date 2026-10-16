<?php

namespace Laravel\Octane\Events;

/*
 * Stand-in for Octane's event at the end of a task (`Octane::concurrently()`), which runs in a
 * sandbox of its own, as a request does.
 */
if (!class_exists(TaskTerminated::class, false)) {
    class TaskTerminated
    {
        public function __construct(public $app = null, public $sandbox = null, public $data = null, public $result = null)
        {
        }
    }
}
