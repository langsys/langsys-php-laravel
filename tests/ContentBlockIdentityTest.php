<?php

namespace Langsys\Laravel\Tests;

use Langsys\SDK\Client;

/**
 * MARK-3 on this package's page route: a content block stamped with its id registers under that id
 * when `TranslateResponse` serves it outside a resolved scope, and registers nothing inside one.
 * The reading is the core's page translator; the test runs it on the real core, off the network.
 * Blade itself stamps no identity host: `@t` prints a phrase, never a block.
 */
class ContentBlockIdentityTest extends TestCase
{
    private Client $client;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.translate_response.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->offlineClient(['es-es' => ['__uncategorized__' => []]]);
        $this->app->instance(Client::class, $this->client);
        $this->app->setLocale('es-ES');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('langsys.translate-page')->get('/outside', fn () => response(
            '<html><body><div data-ls-contentblock="abc123"><p>Terms apply to every order.</p></div></body></html>'
        ));
        $router->middleware('langsys.translate-page')->get('/inside', fn () => response(
            '<html><body><section data-ls-resolved="es-es"><div data-ls-contentblock="abc123"><p>Terms apply to every order.</p></div></section></body></html>'
        ));
    }

    private function _pendingIds(): array
    {
        return array_values(array_filter(array_column($this->client->getPendingContentBlocks(), 'customId')));
    }

    public function testAStampedBlockOutsideAResolvedScopeRegistersUnderItsId(): void
    {
        $this->get('/outside')->assertOk();

        $this->assertSame(['abc123'], $this->_pendingIds());
    }

    public function testAStampedBlockInsideAResolvedScopeRegistersNothing(): void
    {
        $this->get('/inside')->assertOk();

        $this->assertSame([], $this->_pendingIds());
    }
}
