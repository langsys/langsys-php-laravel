<?php

namespace Langsys\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** A queued job that writes a view out, as an export or an SMS body is written. */
class RenderingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static ?string $written = null;

    public function handle(): void
    {
        self::$written = view('feed', ['url' => 'https://example.com/sitemap.xml'])->render();
    }
}
