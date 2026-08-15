<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
use Langsys\SDK\Locale\LocaleDetector;
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
 * `translatePage()` and WHAT to hand it. It never inspects the HTML. Which
 * elements are walked, which attributes are translatable, what `translate="no"`
 * means and how markup is tokenized all belong to langsys/langsys-php.
 */
class TranslateResponse
{
    public function __construct(
        private readonly Client $client,
        private readonly CacheFactory $cache,
    ) {
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

        $translated = $this->_translate($html, LocaleDetector::normalize(app()->getLocale()));

        // A null result means the lookup failed or produced nothing usable;
        // leave the original response untouched rather than blanking a page.
        if ($translated !== null) {
            $response->setContent($translated);
        }

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

    private function _translate(string $html, string $locale): ?string
    {
        $key = $this->_cacheKey($html, $locale);

        if ($key !== null) {
            $cached = $this->_store()->get($key);

            if (is_string($cached)) {
                return $cached;
            }
        }

        // translatePage() resolves the locale from the client rather than an
        // argument, and returns the HTML untouched when it is null. Set it
        // explicitly from the app locale — the same value LangsysTranslator
        // uses — so this middleware and `@t` can never disagree about which
        // language a page is in, and so we never fall through to
        // Client::getLocale(), which auto-detects from $_SERVER and can
        // trigger an HTTP call for the project's base locale.
        $this->client->setLocale($locale);

        try {
            $translated = $this->client->translatePage(
                $html,
                config('langsys.translate_response.category')
            );
        } catch (LangsysException $e) {
            // A translation layer must never be the reason a page 500s. Serve
            // the untranslated response and hand the failure to the app's
            // reporter.
            report($e);

            return null;
        }

        if (!is_string($translated) || $translated === '') {
            return null;
        }

        if ($key !== null) {
            $this->_store()->put($key, $translated, (int) config('langsys.translate_response.cache.ttl', 3600));
        }

        return $translated;
    }

    /**
     * Keyed by the source HTML rather than the route, so a page whose content
     * varies by user or state can never serve another request's translation.
     * That also makes the cache useless for most Laravel pages — a CSRF token
     * or timestamp changes the hash every render — which is why it ships
     * disabled. Turn it on only for genuinely static, high-traffic HTML.
     */
    private function _cacheKey(string $html, string $locale): ?string
    {
        if (!config('langsys.translate_response.cache.enabled')) {
            return null;
        }

        return config('langsys.cache.prefix', 'langsys:') . 'page:' . $locale . ':' . sha1($html);
    }

    private function _store()
    {
        return $this->cache->store(config('langsys.cache.store'));
    }
}
