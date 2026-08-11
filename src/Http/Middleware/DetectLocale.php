<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Langsys\Laravel\Support\LocaleFormatter;
use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale through the configured source chain
 * (query → cookie → session → Accept-Language by default), sets it on both
 * the Laravel app and the Langsys client, and persists an explicit choice
 * (query source) via cookie or session so later requests keep it.
 *
 * Livewire's AJAX endpoint runs through the same `web` middleware group, so
 * component updates resolve translations in the same locale as the page.
 */
class DetectLocale
{
    public function __construct(private readonly Client $client)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        [$locale, $source] = $this->_resolve($request);

        if ($locale) {
            app()->setLocale($locale);
            $this->client->setLocale($locale);

            if ($source === 'query' && config('langsys.locale.persist') === 'session') {
                $request->session()->put(config('langsys.locale.session_key'), $locale);
            }
        }

        $response = $next($request);

        if ($locale && $source === 'query' && config('langsys.locale.persist') === 'cookie') {
            $response->headers->setCookie(
                Cookie::create(config('langsys.locale.cookie'), $locale)
                    ->withExpires(now()->addMinutes((int) config('langsys.locale.cookie_minutes')))
            );
        }

        return $response;
    }

    /** @return array{0: ?string, 1: ?string} [locale, source] */
    private function _resolve(Request $request): array
    {
        foreach (config('langsys.locale.sources', []) as $source) {
            $locale = $this->_fromSource($request, $source);

            if ($locale && $this->_isSupported($locale)) {
                // Canonical BCP 47 for Laravel and downstream consumers; the
                // SDK re-normalizes to its lowercase form internally.
                return [LocaleFormatter::canonicalize($locale), $source];
            }
        }

        return [null, null];
    }

    private function _fromSource(Request $request, string $source): ?string
    {
        return match ($source) {
            'query'   => $request->query(config('langsys.locale.query_param')),
            'cookie'  => $request->cookie(config('langsys.locale.cookie')),
            'session' => $request->hasSession()
                ? $request->session()->get(config('langsys.locale.session_key'))
                : null,
            'header'  => $this->_fromAcceptLanguage($request->header('Accept-Language')),
            default   => null,
        };
    }

    /**
     * Delegates to the SDK, the single source of truth for Accept-Language
     * parsing. Parsing it here instead meant two implementations that
     * disagreed on q-value priority and on whether a bare language got a
     * region — the exact defect langsys/langsys-php fixed in v1.0.0, where the
     * result depended on whether the host had ext-intl.
     *
     * Takes Laravel's request header rather than reading $_SERVER, so a header
     * rewritten by earlier middleware is honoured and test-kernel requests
     * (which don't populate the superglobal) behave like real ones.
     */
    private function _fromAcceptLanguage(?string $header): ?string
    {
        if (!$header) {
            return null;
        }

        return LocaleDetector::fromAcceptLanguage($header);
    }

    private function _isSupported(string $locale): bool
    {
        $supported = config('langsys.locale.supported', []);

        if ($supported === []) {
            return true;
        }

        return in_array(
            LocaleDetector::normalize($locale),
            array_map(LocaleDetector::normalize(...), $supported),
            true
        );
    }
}
