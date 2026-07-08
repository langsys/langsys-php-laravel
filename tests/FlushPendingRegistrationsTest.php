<?php

namespace Langsys\Laravel\Tests;

class FlushPendingRegistrationsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'langsys.locale', 'langsys.flush'])->get('/page', function () {
            return t('A phrase nobody translated yet', 'Landing');
        });
    }

    public function testDiscoveredPhrasesAreFlushedAfterTheResponse(): void
    {
        $this->get('/page?locale=es-ES')->assertOk();

        $this->assertSame(1, $this->fakeClient->flushCalls);
        $this->assertSame([], $this->fakeClient->queuedPhrases);
    }

    public function testNothingIsFlushedWhenAutoFlushIsOff(): void
    {
        config()->set('langsys.auto_flush', false);

        $this->get('/page?locale=es-ES')->assertOk();

        $this->assertSame(0, $this->fakeClient->flushCalls);
        $this->assertNotEmpty($this->fakeClient->queuedPhrases);
    }

    public function testNothingIsFlushedWhenTheQueueIsEmpty(): void
    {
        $this->fakeClient->seed('es-ES', 'Landing', [
            'A phrase nobody translated yet' => 'Una frase ya traducida',
        ]);

        $this->get('/page?locale=es-ES')->assertSee('Una frase ya traducida');

        $this->assertSame(0, $this->fakeClient->flushCalls);
    }
}
