<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;

/**
 * An app that has installed the package but not set LANGSYS_API_KEY /
 * LANGSYS_PROJECT_ID yet. Resolving the client used to throw, so every page
 * that rendered a phrase was a 500 until the .env was filled in. It now boots
 * and renders the base-language text.
 */
class UnconfiguredAppTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('langsys.api_key', null);
        $app['config']->set('langsys.project_id', null);
    }

    protected function setUp(): void
    {
        putenv('LANGSYS_API_KEY');
        putenv('LANGSYS_PROJECT_ID');

        parent::setUp();

        // The real client this time, not the fake the base case binds.
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(\Langsys\Laravel\LangsysTranslator::class);
    }

    public function testTheClientResolvesUnconfigured(): void
    {
        $client = $this->app->make(Client::class);

        $this->assertFalse($client->isConfigured());
    }

    public function testPhrasesRenderInTheBaseLanguageWithParams(): void
    {
        $this->app->setLocale('es');

        $this->assertSame('Hello, Sarah!', t('Hello, {name}!', 'Greetings', ['name' => 'Sarah']));
        $this->assertSame('Checkout', Blade::render("@t('Checkout')"));
    }

    public function testRequireCredentialsMakesItFailAtBoot(): void
    {
        $this->app['config']->set('langsys.require_credentials', true);
        $this->app->forgetInstance(Client::class);

        $this->expectException(LangsysException::class);

        $this->app->make(Client::class);
    }
}
