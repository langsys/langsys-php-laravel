<?php

namespace Langsys\Laravel\Console;

use Illuminate\Console\Command;
use Langsys\Laravel\Support\ValueSetDiscovery;

/** Caches the discovered value-set declarations (FRM-7), as `event:cache` caches listeners. */
class CacheCommand extends Command
{
    protected $signature = 'langsys:cache';

    protected $description = 'Cache the translatable value sets found in the app';

    public function handle(): int
    {
        $classes = ValueSetDiscovery::cache();
        $this->info(count($classes) . ' value sets cached.');

        return self::SUCCESS;
    }
}
