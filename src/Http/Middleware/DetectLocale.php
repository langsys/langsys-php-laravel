<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Langsys\Laravel\Support\LocaleFormatter;
use Langsys\Laravel\Support\RequestLocaleWiring;
use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;
use Langsys\SDK\Locale\RequestLocale;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The request locale under Laravel's convention (SRV-6). Laravel owns it: where the application
 * has already set `app()->getLocale()` — its own middleware, a route, a user preference — that
 * locale is served, and the Langsys client is told it in the project's form. Only while nothing has
 * set it this request does this middleware resolve one, from the configured `sources` in order,
 * and set it on both. Every candidate is validated against the locales the project serves (and
 * `supported`, when set); the SDK's own matcher and `Accept-Language` negotiation decide each one.
 * The response varies on what that choice depended on, and a choice made in the query string is
 * persisted.
 *
 * Livewire's AJAX endpoint runs through the same `web` middleware group, so component updates
 * resolve translations in the same locale as the page.
 */
class DetectLocale
{
    /**
     * Set on the request when anything calls `app()->setLocale()` (Laravel's `LocaleUpdated`),
     * which is how the app says it resolved the locale. Comparing against `app.locale` cannot tell:
     * `setLocale()` rewrites that config value too.
     */
    public const RESOLVED = 'langsys.locale_resolved';

    /** What each source varies on. The query string is part of the URL, the cache key already. */
    private const VARY = ['query' => null, 'cookie' => 'Cookie', 'session' => 'Cookie', 'header' => 'Accept-Language'];

    public function __construct(private readonly Client $client)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(self::RESOLVED) === true) {
            // The SDK validates Laravel's locale and maps it to the project's form; a locale the app
            // resolved is the app's to vary on, so nothing is sent (SRV-6).
            $choice = $this->client->resolveRequestLocale(['framework' => app()->getLocale()], ['send_vary' => false]);

            if ($choice['locale'] !== null) {
                $this->client->setLocale($choice['locale']);
            }

            return $next($request);
        }

        try {
            $project = $this->client->getProject();
        } catch (Throwable) {
            // Nothing can be validated without the project's locales; Laravel's stands, untouched.
            return $next($request);
        }

        $base = LocaleDetector::normalize((string) ($project['base_locale'] ?? ''));
        $served = $this->_served($base, $project['target_locales'] ?? []);
        $defaults = is_array($project['default_locales'] ?? null) ? $project['default_locales'] : [];

        [$locale, $source, $vary] = $this->_resolve($request, $served, $base, $defaults);

        app()->setLocale(LocaleFormatter::canonicalize($locale));
        $this->client->setLocale($locale);

        $persist = $source === 'query' ? config('langsys.locale.persist') : null;

        if ($persist === 'session' && $request->hasSession()) {
            $request->session()->put(config('langsys.locale.session_key'), LocaleFormatter::canonicalize($locale));
        }

        $response = $next($request);

        if ($vary !== null) {
            $response->setVary($vary, false);
        }

        if ($persist === 'cookie') {
            $response->headers->setCookie(
                Cookie::create(config('langsys.locale.cookie'), LocaleFormatter::canonicalize($locale))
                    ->withExpires(now()->addMinutes((int) config('langsys.locale.cookie_minutes')))
            );
        }

        return $response;
    }

    /**
     * The first usable candidate from the configured sources, else the base locale. A header that
     * was read and did not decide still varies the response: a different header could have.
     *
     * @return array{0: string, 1: ?string, 2: ?string} [locale, source, vary]
     */
    private function _resolve(Request $request, array $served, string $base, array $defaults): array
    {
        $headerRead = false;

        foreach (config('langsys.locale.sources', []) as $source) {
            if ($source === 'header') {
                $headerRead = trim((string) $request->header('Accept-Language')) !== '';
                $negotiated = RequestLocale::resolve($served, $base, ['accept_language' => $request->header('Accept-Language')], RequestLocaleWiring::headerOnly(), $defaults);

                if ($negotiated['source'] === 'accept-language') {
                    return [$negotiated['locale'], $source, self::VARY[$source]];
                }

                continue;
            }

            $matched = RequestLocale::match($this->_candidate($request, $source), $served, $defaults);

            if ($matched !== null) {
                return [$matched, $source, self::VARY[$source] ?? null];
            }
        }

        return [$base, null, $headerRead ? self::VARY['header'] : null];
    }

    private function _candidate(Request $request, string $source): mixed
    {
        return match ($source) {
            'query'   => $request->query(config('langsys.locale.query_param')),
            'cookie'  => $request->cookie(config('langsys.locale.cookie')),
            'session' => $request->hasSession() ? $request->session()->get(config('langsys.locale.session_key')) : null,
            default   => null,
        };
    }

    /** The project's base and target locales, narrowed to `supported` when the app lists any. */
    private function _served(string $base, array $targets): array
    {
        $served = array_map(LocaleDetector::normalize(...), array_merge([$base], $targets));
        $supported = array_map(LocaleDetector::normalize(...), config('langsys.locale.supported', []));

        return $supported === [] ? $served : array_values(array_intersect($served, $supported));
    }
}
