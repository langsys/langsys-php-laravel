<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerStarting;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\RecordingClient;
use Langsys\Laravel\Tests\Fixtures\TranslatingJob;
use Langsys\SDK\Client;
use RuntimeException;

require_once __DIR__ . '/Fixtures/Octane/RequestTerminated.php';
require_once __DIR__ . '/Fixtures/Octane/TaskTerminated.php';
require_once __DIR__ . '/Fixtures/Octane/TickTerminated.php';
require_once __DIR__ . '/Fixtures/Octane/WorkerStarting.php';

/**
 * GATE-3 and SRV-2 where a Laravel container outlives one unit of work: an
 * Octane worker between requests, and a queue worker between jobs. The Client
 * is a container singleton, so without an explicit boundary the next unit
 * inherits this one's write decision and in-memory catalog.
 *
 * Each case asserts something the NEXT unit of work can observe — a leftover
 * decision, a stale translation — on the real SDK, and runs over every
 * boundary, because a rule proven on one route is only asserted on the rest
 * (CONF-1).
 */
class RequestScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('es-ES');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/dispatch', function () {
            $client = app(Client::class);
            $client->decideWrite(true);
            \Illuminate\Support\Facades\Bus::dispatchSync(new TranslatingJob('Save'));

            return response()->json(['calls' => $client->calls, 'decision' => $client->writeDecision()]);
        });
        $router->get('/dispatch-immediate', function () {
            $client = app(Client::class);
            $client->decideWrite(true);
            dispatch((new TranslatingJob('Save'))->onConnection('immediate'));

            return response()->json(['calls' => $client->calls, 'decision' => $client->writeDecision()]);
        });
    }

    /**
     * Every way a unit of work ends in a long-lived Laravel process. The queue
     * cases do real work first: a job that renders a phrase and finishes, and
     * one that renders and then throws, each run here as a worker runs it, on
     * a connection of a worker's.
     *
     * @return array<string, \Closure(): void>
     */
    private function _boundaries(): array
    {
        $queue = $this->_workerQueue();

        return [
            'queue job processed' => fn () => $queue->push(new TranslatingJob('Save')),
            'queue job threw'     => function () use ($queue) {
                try {
                    $queue->push(new TranslatingJob('Save', fail: true));
                } catch (RuntimeException) {
                    // The failure is the job's own; the boundary is what is under test.
                }
            },
            'octane request'      => fn () => event(new RequestTerminated($this->app, $this->app)),
            'octane task'         => fn () => event(new TaskTerminated($this->app, $this->app)),
            'octane tick'         => fn () => event(new TickTerminated($this->app, $this->app)),
        ];
    }

    /**
     * Octane serves each request in a sandbox, a clone of the worker's container, and makes it the
     * current container. What the request resolves lives there, never in the worker's own.
     */
    private function _inOctaneSandbox(\Closure $request): \Illuminate\Foundation\Application
    {
        $sandbox = clone $this->app;
        \Illuminate\Container\Container::setInstance($sandbox);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($sandbox);

        try {
            $request($sandbox);
        } finally {
            \Illuminate\Container\Container::setInstance($this->app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
        }

        return $sandbox;
    }

    /** The end of an Octane request ends the scope of the client its sandbox built, which the worker's container never holds. */
    public function testAnOctaneRequestEndsTheScopeOfTheClientItsSandboxBuilt(): void
    {
        $this->app->forgetInstance(Client::class);
        $client = $this->offlineClient();

        $sandbox = $this->_inOctaneSandbox(fn ($sandbox) => $sandbox->instance(Client::class, $client));
        event(new RequestTerminated($this->app, $sandbox));

        $this->assertSame(['flush', 'reset'], $client->calls);
        $this->assertFalse($this->app->resolved(Client::class), "Control: the worker's container never held it.");
    }

    /**
     * REG-8 and CACHE-2: the backoff clock outlives the request. The worker builds the client as it
     * starts, so every request's sandbox is served by that one client, and a request during an
     * outage reads the failure the one before it recorded instead of fetching again.
     */
    public function testAnOctaneWorkerKeepsOneClientSoTheBackoffClockOutlivesTheRequest(): void
    {
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(LangsysTranslator::class);
        config()->set(['langsys.api_key' => 'test-key', 'langsys.project_id' => 'test-project', 'langsys.api_url' => self::UNREACHABLE_API]);

        event(new WorkerStarting($this->app));
        $clients = [];

        foreach (['first', 'second'] as $request) {
            $sandbox = $this->_inOctaneSandbox(function ($sandbox) use (&$clients) {
                $this->assertSame('Pay now', __('Pay now'));
                $clients[] = $sandbox->make(Client::class);
            });
            event(new RequestTerminated($this->app, $sandbox));
        }

        $this->assertSame($clients[0], $clients[1], 'Both requests are served by the client the worker holds.');
        $failures = (new \ReflectionProperty(Client::class, 'translationFetchFailures'))->getValue($clients[1]);
        $this->assertSame([1], array_values(array_column($failures, 'failures')), 'The second request read the clock the first one set, and fetched nothing.');
    }

    /** `__()` through the translator the worker warmed asks the client of the request being served, not one of the worker's own. */
    public function testInAnOctaneSandboxTranslationAsksTheRequestsClient(): void
    {
        $this->app['translator'];
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(LangsysTranslator::class);
        $client = $this->offlineClient(['es-es' => ['__uncategorized__' => ['Save' => 'Guardar']]]);

        $this->_inOctaneSandbox(function ($sandbox) use ($client) {
            $sandbox->instance(Client::class, $client);
            $this->assertSame('Guardar', __('Save'));
        });

        $this->assertFalse($this->app->resolved(Client::class), "The worker's container built no client of its own.");
    }

    /** The app setting the locale marks the request being served, not the one the worker booted with (SRV-6). */
    public function testInAnOctaneSandboxTheAppSettingTheLocaleMarksTheRequestServed(): void
    {
        $served = \Illuminate\Http\Request::create('/');

        $this->_inOctaneSandbox(function ($sandbox) use ($served) {
            $sandbox->instance('request', $served);
            app()->setLocale('fr');
        });

        $this->assertTrue($served->attributes->get(\Langsys\Laravel\Http\Middleware\DetectLocale::RESOLVED));
    }

    /**
     * A job on a sync-driver connection runs inside whatever dispatched it — here, a request — so its end
     * is not a boundary: nothing is sent while the request is served, and the request keeps its
     * client's state.
     */
    public function testAJobRunOnTheSyncConnectionEndsNothing(): void
    {
        $this->_bindOfflineClient();

        $this->getJson('/dispatch')->assertExactJson(['calls' => [], 'decision' => true]);

        // Any connection on the sync driver runs the job here, whatever the app named it.
        config()->set('queue.connections.immediate', ['driver' => 'sync']);
        $this->_bindOfflineClient();
        $this->getJson('/dispatch-immediate')->assertExactJson(['calls' => [], 'decision' => true]);
    }

    /** A queue that runs each job here, at once, and reports it as a worker's connection does. */
    private function _workerQueue(): \Illuminate\Contracts\Queue\Queue
    {
        return $this->app['queue']->connection('sync')->setConnectionName('redis');
    }

    private function _bindOfflineClient(): RecordingClient
    {
        $client = $this->offlineClient(['es-es' => ['__uncategorized__' => ['Save' => 'Guardar']]]);

        $this->app->instance(Client::class, $client);
        $this->app->forgetInstance(LangsysTranslator::class);

        return $client;
    }

    /**
     * resetRequestState() drops whatever is still queued, so the flush comes
     * first; a reset before it would discard this unit's discoveries unsent.
     */
    public function testEveryBoundaryFlushesAndThenResets(): void
    {
        foreach ($this->_boundaries() as $boundary => $end) {
            $client = $this->_bindOfflineClient();

            $end();

            $this->assertSame(['flush', 'reset'], $client->calls, "Boundary: {$boundary}");
        }
    }

    public function testTheNextUnitOfWorkStartsWithoutAWriteDecision(): void
    {
        foreach ($this->_boundaries() as $boundary => $end) {
            $client = $this->_bindOfflineClient();
            $client->decideWrite(true);

            $end();

            $this->assertNull($client->writeDecision(), "Boundary: {$boundary} carried this unit's write decision into the next.");
        }
    }

    public function testTheNextUnitOfWorkReadsTheCatalogAfresh(): void
    {
        foreach ($this->_boundaries() as $boundary => $end) {
            $this->_bindOfflineClient();
            $this->assertSame('Guardar', t('Save'), 'Control: this unit must have read the seeded catalog.');

            $this->seedCatalog('es-es', ['__uncategorized__' => ['Save' => 'Salvar']]);
            $end();

            $this->assertSame('Salvar', t('Save'), "Boundary: {$boundary} served the previous unit's catalog.");
        }
    }

    /**
     * A boundary must not build a Client the unit of work never used: it has
     * nothing to flush, and every job and every Octane request would pay for
     * building one.
     */
    public function testABoundaryNeverBuildsAClientNobodyUsed(): void
    {
        $this->app->forgetInstance(Client::class);
        config()->set('langsys.api_key', null);

        // A worker's job, on a worker's connection: a sync-driver connection's is no boundary at all.
        $job = new SyncJob($this->app, '{}', 'redis', 'default');
        event(new JobProcessed('redis', $job));
        event(new JobExceptionOccurred('redis', $job, new RuntimeException('failed')));
        event(new RequestTerminated($this->app, $this->app));
        event(new TaskTerminated($this->app, $this->app));
        event(new TickTerminated($this->app, $this->app));

        $this->assertFalse($this->app->resolved(Client::class));
    }

    /**
     * WIRE-4, REG-8 and REG-10 at a boundary: a phrase a job discovers, flushed against an
     * unreachable API, fails nothing. The job completes and the boundary still resets. What the
     * flush could not send belongs to this unit of work and ends with it, so the next job starts
     * with an empty queue and re-collects its own misses.
     */
    public function testAFlushThatCannotReachTheApiFailsNothing(): void
    {
        $client = $this->_bindOfflineClient();
        $client->decideWrite(true);

        $this->_workerQueue()->push(new TranslatingJob('A phrase nobody translated yet'));

        $this->assertSame(['flush', 'reset'], $client->calls);
        $this->assertSame([], $client->getPendingPhrases(), "This job's phrases must not ride the next job's send.");
    }

    /**
     * REG-10 at a boundary: a write skipped because the key may not write is reported by the core
     * as a failure naming its reason, and the binding passes nothing success-shaped on. The
     * boundary has no caller to hand the result to; the core logs it.
     */
    public function testASkippedWriteAtABoundaryIsNamedByTheCore(): void
    {
        $client = $this->_bindOfflineClient();
        $client->decideWrite(false);

        $this->_workerQueue()->push(new TranslatingJob('A phrase a read key found'));

        $this->assertSame(['flush', 'reset'], $client->calls);
        $this->assertFalse($client->lastFlush['success']);
        $this->assertSame('not_write_enabled', $client->lastFlush['reason']);
    }
}
