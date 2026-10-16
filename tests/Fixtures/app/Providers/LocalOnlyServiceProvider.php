<?php

namespace Langsys\Laravel\Tests\Fixtures\app\Providers;

/**
 * A provider for a package installed only in development, as Laravel's documented Telescope setup
 * has it: deployed with `composer install --no-dev`, its parent class does not exist.
 */
class LocalOnlyServiceProvider extends \Laravel\Telescope\TelescopeApplicationServiceProvider
{
}
