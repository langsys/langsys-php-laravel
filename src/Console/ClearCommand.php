<?php

namespace Langsys\Laravel\Console;

use Illuminate\Console\Command;
use Langsys\Laravel\Support\ValueSetDiscovery;

class ClearCommand extends Command
{
    protected $signature = 'langsys:clear';

    protected $description = 'Remove the cached value sets, so the app is scanned again';

    public function handle(): int
    {
        ValueSetDiscovery::clear();
        $this->info('Value set cache cleared.');

        return self::SUCCESS;
    }
}
