<?php

namespace Langsys\Laravel\Tests;

use ArrayObject;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Client;

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

    /**
     * SRV-3 asks for the order of events, not merely that a flush happened: a
     * flush inside handle() still passes "it was flushed" while spending the
     * visitor's latency on registration. RequestHandled fires once the
     * response exists and before any terminable middleware runs.
     */
    public function testTheFlushRunsOnlyOnceTheResponseExists(): void
    {
        $timeline = new ArrayObject();
        Event::listen(RequestHandled::class, fn () => $timeline[] = 'response');

        $client = new class extends FakeClient {
            public ?ArrayObject $timeline = null;

            public function flushPendingRegistrations()
            {
                $this->timeline[] = 'flush';

                return parent::flushPendingRegistrations();
            }
        };
        $client->timeline = $timeline;
        $this->app->instance(Client::class, $client);

        $this->get('/page?locale=es-ES')->assertOk();

        $this->assertSame(['response', 'flush'], $timeline->getArrayCopy());
    }
}
