<?php

namespace Langsys\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

/**
 * A queued job that renders one phrase. Run at once on the sync queue, it
 * goes through the real job event path — JobProcessed, or JobExceptionOccurred
 * when $fail is set — under whichever connection name the queue reports: a
 * worker's is the boundary a long-lived queue worker gives the service
 * provider, and a sync-driver connection's is none.
 */
class TranslatingJob implements ShouldQueue
{
    use Queueable;

    /** What the job last printed. */
    public static ?string $printed = null;

    public function __construct(
        public string $phrase,
        public bool $fail = false,
    ) {
    }

    public function handle(): void
    {
        self::$printed = t($this->phrase);

        if ($this->fail) {
            throw new RuntimeException('The job failed after rendering.');
        }
    }
}
