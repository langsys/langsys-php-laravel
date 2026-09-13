<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Langsys\SDK\Client;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opt-in middleware that runs the SDK's page translator over a rendered HTML
 * response, translating every text node and translatable attribute without
 * hand-tagging. This is the "automatic" half of the coverage model: it closes
 * the gap `@t` structurally cannot reach — text Alpine injects from a JS
 * expression (`x-text="'Save changes'"`, `:aria-label="…"`) never becomes a
 * DOM node you can wrap.
 *
 * NEVER COMBINE WITH `@t` — pick one mode per project. If both run, this
 * middleware re-walks nodes `@t` already translated, looks the *translated*
 * string up as a source phrase, misses, and REGISTERS it. A Spanish "Guardar"
 * then enters the catalog every Langsys SDK shares as though it were source
 * text. Use `translate="no"` on any subtree that is already resolved.
 *
 * The wrapper's boundary: this class decides only WHETHER to call
 * `translatePage()` and WHAT to hand it. It never inspects the HTML, and it
 * keeps nothing: a translated page is not cached here (BIND-5). Which
 * elements are walked, which attributes are translatable, what `translate="no"`
 * means, how markup is tokenized and what a failure degrades to all belong to
 * langsys/langsys-php.
 */
class TranslateResponse
{
    public function __construct(private readonly Client $client)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->_shouldTranslate($request, $response)) {
            return $response;
        }

        $html = $response->getContent();

        if (!is_string($html) || trim($html) === '') {
            return $response;
        }

        // translatePage() resolves the locale from the client rather than an
        // argument, and returns the HTML untouched when it is null. Set it
        // explicitly from the app locale — the same value LangsysTranslator
        // uses — so this middleware and `@t` can never disagree about which
        // language a page is in, and so we never fall through to
        // Client::getLocale(), which auto-detects from $_SERVER and can
        // trigger an HTTP call for the project's base locale.
        $this->client->setLocale(app()->getLocale());

        // Served as returned. translatePage() never throws and hands back the
        // source HTML on every degraded path (WIRE-4), so a guard here would be
        // a second fallback that could only drift from the SDK's own.
        $response->setContent($this->client->translatePage(
            $html,
            config('langsys.translate_response.category')
        ));

        return $response;
    }

    private function _shouldTranslate(Request $request, Response $response): bool
    {
        if (!config('langsys.translate_response.enabled')) {
            return false;
        }

        // Streamed and file responses have no buffered body to rewrite —
        // getContent() is false or would drain the stream. Redirects carry no
        // meaningful copy.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->isRedirection()) {
            return false;
        }

        // Content type is the load-bearing guard: it excludes JSON (including
        // every Livewire and Inertia XHR round-trip) without this middleware
        // needing to know those libraries exist.
        if (!str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        return $this->_pathIsInScope($request);
    }

    /**
     * `except` wins over `only` so a broad include can be carved out — the
     * usual shape is "the whole site except /admin".
     */
    private function _pathIsInScope(Request $request): bool
    {
        $except = config('langsys.translate_response.except', []);

        if ($except !== [] && $request->is(...$except)) {
            return false;
        }

        $only = config('langsys.translate_response.only', []);

        return $only === [] || $request->is(...$only);
    }
}
