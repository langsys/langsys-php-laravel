<?php

namespace Langsys\Laravel\Console;

use Illuminate\Console\Command;
use Langsys\Laravel\Support\AppMessageDiscovery;
use Langsys\Laravel\Support\ValueSetDiscovery;

/** Caches the discovered value-set declarations (FRM-7) and app messages (MSG-7), as `event:cache` caches listeners. */
class CacheCommand extends Command
{
    protected $signature = 'langsys:cache';

    protected $description = 'Cache the translatable value sets and app messages found in the app';

    public function handle(): int
    {
        $classes = ValueSetDiscovery::cache();
        $messages = AppMessageDiscovery::cache();
        $this->info(count($classes) . ' value sets and ' . count($messages) . ' app messages cached.');

        return self::SUCCESS;
    }
}
