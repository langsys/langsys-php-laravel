<?php

namespace Langsys\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

/**
 * A queued job that renders one phrase. Pushed onto the sync connection it
 * runs through the real worker event path — JobProcessed, or
 * JobExceptionOccurred when $fail is set — which is the boundary a long-lived
 * queue worker gives the service provider.
 */
class TranslatingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $phrase,
        public string $category = 'UI',
        public bool $fail = false,
    ) {
    }

    public function handle(): void
    {
        t($this->phrase, $this->category);

        if ($this->fail) {
            throw new RuntimeException('The job failed after rendering.');
        }
    }
}
