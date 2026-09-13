<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Laravel\Octane\Events\RequestTerminated;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\RecordingClient;
use Langsys\Laravel\Tests\Fixtures\TranslatingJob;
use Langsys\SDK\Client;
use RuntimeException;

require_once __DIR__ . '/Fixtures/Octane/RequestTerminated.php';

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

    /**
     * Every way a unit of work ends in a long-lived Laravel process. The queue
     * cases do real work first: a job that renders a phrase and finishes, and
     * one that renders and then throws.
     *
     * @return array<string, \Closure(): void>
     */
    private function _boundaries(): array
    {
        $queue = $this->app['queue']->connection('sync');

        return [
            'queue job processed' => fn () => $queue->push(new TranslatingJob('Save')),
            'queue job threw'     => function () use ($queue) {
                try {
                    $queue->push(new TranslatingJob('Save', fail: true));
                } catch (RuntimeException) {
                    // The failure is the job's own; the boundary is what is under test.
                }
            },
            'octane request'      => fn () => event(new RequestTerminated()),
        ];
    }

    private function _bindOfflineClient(): RecordingClient
    {
        $client = $this->offlineClient(['es-es' => ['UI' => ['Save' => 'Guardar']]]);

        $this->app->instance(Client::class, $client);
        $this->app->forgetInstance(LangsysTranslator::class);

        return $client;
    }

    /**
     * resetRequestState() does not send the queue, so the flush comes first; a
     * reset before it would judge this unit's discoveries against a cleared
     * write decision.
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
            $this->assertSame('Guardar', t('Save', 'UI'), 'Control: this unit must have read the seeded catalog.');

            $this->seedCatalog('es-es', ['UI' => ['Save' => 'Salvar']]);
            $end();

            $this->assertSame('Salvar', t('Save', 'UI'), "Boundary: {$boundary} served the previous unit's catalog.");
        }
    }

    /**
     * A boundary must not build a Client the unit of work never used: without
     * credentials the SDK's constructor throws, which would fail every job and
     * every Octane request in an app that has not configured Langsys.
     */
    public function testABoundaryNeverBuildsAClientNobodyUsed(): void
    {
        $this->app->forgetInstance(Client::class);
        config()->set('langsys.api_key', null);

        $job = new SyncJob($this->app, '{}', 'sync', 'default');
        event(new JobProcessed('sync', $job));
        event(new JobExceptionOccurred('sync', $job, new RuntimeException('failed')));
        event(new RequestTerminated());

        $this->assertFalse($this->app->resolved(Client::class));
    }

    /**
     * WIRE-4 and REG-10 at a boundary: a phrase a job discovers, flushed
     * against an unreachable API, fails nothing. The job completes, the
     * boundary still resets, and the undelivered phrase stays queued for a
     * later flush rather than being dropped as though it were sent.
     */
    public function testAFlushThatCannotReachTheApiFailsNothing(): void
    {
        $client = $this->_bindOfflineClient();
        $client->decideWrite(true);

        $this->app['queue']->connection('sync')->push(new TranslatingJob('A phrase nobody translated yet'));

        $this->assertSame(['flush', 'reset'], $client->calls);
        $this->assertContains('A phrase nobody translated yet', array_column($client->getPendingPhrases(), 'phrase'));
    }
}
