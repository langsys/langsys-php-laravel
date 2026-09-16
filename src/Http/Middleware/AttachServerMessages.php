<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Langsys\Laravel\Messages\MessageValidator;
use Langsys\SDK\Messages\ServerMessage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the entries of a failed validation beside Laravel's own error body (MSG-1).
 *
 * The envelope stays the application's: a 422 keeps `message` and `errors` exactly as Laravel
 * writes them, and the entries sit under a configured key. A form that redirects carries them in
 * the session instead, so the page rendered next can hand them to its own SDK.
 *
 * The entries carry source text, never a translation — the client renders that from
 * `entry.template` (MSG-5). Laravel's pipeline attaches the exception it rendered to the response,
 * which is what lets this read the validator without the application wiring anything.
 *
 * On the way in it does the other half of MSG-12: entries flashed by the request that failed are
 * shared with Inertia before the page renders, so the page it redirected to receives them as a
 * prop. Inertia's own conditional props are no use here — `lazy` and `optional` props are withheld
 * from exactly the full page load this has to reach — so the sharing is conditional instead, and a
 * page that follows no failure carries no prop of ours at all.
 */
class AttachServerMessages
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = config('langsys.messages.response_key');
        $flashed = $request->hasSession() ? $request->session()->get($key) : null;

        if ($flashed && class_exists(Inertia::class)) {
            Inertia::share($key, $flashed);
        }

        $response = $next($request);
        $entries = $this->_entries($response);

        if ($entries === []) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            // Only a body we can extend: anything else is the application's shape to keep.
            if (is_array($data)) {
                $data[$key] = $entries;
                $response->setData($data);
            }

            return $response;
        }

        if ($response instanceof RedirectResponse && $request->hasSession()) {
            $request->session()->flash($key, $entries);
        }

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function _entries(Response $response): array
    {
        $exception = $response->exception ?? null;

        if (!$exception instanceof ValidationException || !$exception->validator instanceof MessageValidator) {
            return [];
        }

        return array_map(fn (ServerMessage $message) => $message->toArray(), $exception->validator->serverMessages());
    }
}
