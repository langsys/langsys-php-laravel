<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Langsys\SDK\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminable middleware that flushes the SDK's pending-registration queue
 * (phrases/content blocks discovered during the request under a write key)
 * AFTER the response is sent — aligning the flush with Laravel's lifecycle
 * instead of the SDK's register_shutdown_function (which then no-ops on an
 * empty queue, and never fires between Octane requests).
 *
 * When to flush is this middleware's; everything else is the SDK's.
 * flushPendingRegistrations() returns at once on an empty queue, drops the
 * queue without a request when this request may not write (REG-1), and records
 * every failed send in its result rather than throwing — so none of that is
 * decided again here.
 */
class FlushPendingRegistrations
{
    /** The container, not the Client: a request that built none has nothing queued, and building one without credentials throws. */
    public function __construct(private readonly Container $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** Runs once the response has been sent, so registration never spends the visitor's latency (SRV-3). */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->app->resolved(Client::class)) {
            $this->app->make(Client::class)->flushPendingRegistrations();
        }
    }
}
