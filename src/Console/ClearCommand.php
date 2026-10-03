<?php

namespace Langsys\Laravel\Console;

use Illuminate\Console\Command;
use Langsys\Laravel\Support\AppMessageDiscovery;
use Langsys\Laravel\Support\ValueSetDiscovery;

class ClearCommand extends Command
{
    protected $signature = 'langsys:clear';

    protected $description = 'Remove the cached value sets and app messages, so the app is scanned again';

    public function handle(): int
    {
        ValueSetDiscovery::clear();
        AppMessageDiscovery::clear();
        $this->info('Value set and app message caches cleared.');

        return self::SUCCESS;
    }
}
