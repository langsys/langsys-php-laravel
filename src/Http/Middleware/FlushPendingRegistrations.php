<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminable middleware that flushes the SDK's pending-registration queue
 * (phrases/content blocks discovered during the request under a write key)
 * AFTER the response is sent — aligning the flush with Laravel's lifecycle
 * instead of the SDK's register_shutdown_function (which then no-ops on an
 * empty queue, and never fires between Octane requests).
 *
 * flushPendingRegistrations() itself silently skips read-only keys and empty
 * queues; failures are swallowed so token registration can never break a
 * response.
 */
class FlushPendingRegistrations
{
    public function __construct(private readonly Client $client)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (!config('langsys.auto_flush') || !$this->client->hasPendingRegistrations()) {
            return;
        }

        try {
            $this->client->flushPendingRegistrations();
        } catch (LangsysException) {
            // Never break the request lifecycle over token registration.
        }
    }
}
