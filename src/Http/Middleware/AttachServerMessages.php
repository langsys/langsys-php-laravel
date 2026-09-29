<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Langsys\Laravel\Messages\MessageValidator;
use Langsys\Laravel\Support\RequestLocaleWiring;
use Langsys\SDK\Client;
use Langsys\SDK\Messages\ServerMessage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the entries of a failed validation beside Laravel's own error body (MSG-1).
 *
 * The envelope stays the application's: a 422 keeps `message` and `errors` exactly as Laravel
 * writes them, and the entries sit under a configured key. A form that redirects carries them in
 * the session instead, so the page rendered next can hand them to its own SDK.
 *
 * Every entry carries its source `template` and `params`, which a client SDK renders (MSG-5). In a
 * JSON response its `message` is in the request's language, for a client with no SDK (FRM-5); across
 * a redirect it stays the source fill. Laravel's pipeline attaches the exception it rendered to the
 * response, which is what lets this read the validator without the application wiring anything.
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
        $messages = $this->_messages($response);

        if ($messages === []) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            // Only a body we can extend: anything else is the application's shape to keep.
            if (is_array($data)) {
                $data[$key] = $this->_negotiated($request, $response, $messages);
                $response->setData($data);
            }

            return $response;
        }

        if ($response instanceof RedirectResponse && $request->hasSession()) {
            $request->session()->flash($key, self::_entries($messages));
        }

        return $response;
    }

    /**
     * FRM-5: a JSON response is read by a client that may have no SDK, so each entry's `message` is
     * in the request's language — the locale the app resolved when it resolved one (SRV-6), else
     * Accept-Language negotiated against the project's locales — beside the `template` and `params`
     * an SDK renders itself. The core negotiates and translates; the headers go on Laravel's own
     * response. A redirect's entries stay source: the page they reach has its own SDK (FRM-4).
     *
     * @param  list<ServerMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function _negotiated(Request $request, JsonResponse $response, array $messages): array
    {
        $client = app(Client::class);
        $choice = $request->attributes->get(DetectLocale::RESOLVED) === true
            ? $client->resolveRequestLocale(['framework' => app()->getLocale()], ['send_vary' => false])
            : $client->resolveRequestLocale(['accept_language' => $request->header('Accept-Language')], RequestLocaleWiring::headerOnly());
        $locale = $choice['locale'];

        if ($locale !== null) {
            $response->headers->set('Content-Language', $locale);
        }

        if ($choice['vary'] !== null) {
            $response->setVary($choice['vary'], false);
        }

        return self::_entries($messages, fn (ServerMessage $message) => $locale === null ? $message->getMessage() : $client->translateMessage($message, $locale));
    }

    /** @return list<ServerMessage> */
    private function _messages(Response $response): array
    {
        $exception = $response->exception ?? null;

        if (!$exception instanceof ValidationException || !$exception->validator instanceof MessageValidator) {
            return [];
        }

        return $exception->validator->serverMessages();
    }

    /**
     * @param  list<ServerMessage>  $messages
     * @param  (callable(ServerMessage): string)|null  $message  The entry's `message`, when not its source fill.
     * @return list<array<string, mixed>>
     */
    private static function _entries(array $messages, ?callable $message = null): array
    {
        $pieces = (array) config('langsys.messages.pieces', []);

        return array_map(function (ServerMessage $entry) use ($message, $pieces) {
            $piecesOut = $entry->toArray();

            if ($message !== null) {
                $piecesOut['message'] = $message($entry);
            }

            return self::_named($piecesOut, $pieces);
        }, $messages);
    }

    /** MSG-1: each piece under the name the application chose for it, in the core's order. */
    private static function _named(array $entry, array $pieces): array
    {
        $named = [];

        foreach ($entry as $piece => $value) {
            $named[$pieces[$piece] ?? $piece] = $value;
        }

        return $named;
    }
}
