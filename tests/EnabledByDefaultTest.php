<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Messages\MessageValidator;
use Langsys\Laravel\Translation\CatalogTranslator;

/** FRM-1: installing the package is enough; nothing has to be switched on. */
class EnabledByDefaultTest extends TestCase
{
    public function testInstallingIsEnough(): void
    {
        $this->assertTrue(config('langsys.enabled'));
        $this->assertInstanceOf(CatalogTranslator::class, $this->app['translator']);
        $this->assertInstanceOf(MessageValidator::class, \Illuminate\Support\Facades\Validator::make([], []));

        // Installing is the configuration (MIG-1): the client is given Laravel's own base-language files.
        $this->app->forgetInstance(\Langsys\SDK\Client::class);
        $this->assertNotNull($this->app->make(\Langsys\SDK\Client::class)->getLegacyKeys());
    }
}
