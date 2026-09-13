<?php

namespace Langsys\Laravel;

use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;

/**
 * The service every integration point (helper, @t directive, facade) calls.
 * Thin by design: interpolation lives in langsys/langsys-php (v1.0.0+), whose
 * Interpolator is verified against the JS SDK's implementation, so params and
 * ICU pluralization are delegated rather than reimplemented here — a second
 * implementation would silently drift from the catalog the JS SDKs share.
 *
 * This is also the mockable seam for app tests — the SDK's cURL client is
 * concrete and non-injectable, so fake this (or bind a fake Client) instead of
 * stubbing HTTP.
 */
class LangsysTranslator
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Translate a phrase. Mirrors the JS SDKs' t(phrase, category?, params?):
     * the phrase is both the lookup key and the base-language default, and
     * `{name}` placeholders are substituted from $params.
     *
     * $params is handed to the SDK rather than applied afterwards so that
     * registration still queues the RAW placeholder-bearing phrase — building
     * the string first (sprintf) would register a new catalog entry per runtime
     * value and pollute the catalog every Langsys SDK shares.
     *
     * Everything past the locale is the SDK's, and its result is returned as
     * is. Client::translate() never throws and falls back to the interpolated
     * source phrase on every degraded path — an unreachable API, a registered
     * but untranslated phrase (WIRE-4, CAT-2). This class used to catch and
     * fall back as well; once the SDK did, that copy could only drift from it.
     */
    public function translate(string $phrase, ?string $category = null, array $params = [], ?string $locale = null): string
    {
        // Default to the app locale (set by the DetectLocale middleware), not
        // Client::getLocale() — the latter auto-detects from $_SERVER and can
        // fall back to an HTTP call for the project's base locale when unset.
        //
        // Normalized here because Client::translate() keys its catalog by the
        // locale it is handed, verbatim: `es-ES` and `es-es` would be two cache
        // entries and two fetches (WIRE-3). setLocale() normalizes; translate()
        // does not.
        $locale = LocaleDetector::normalize($locale ?? app()->getLocale());

        return $this->client->translate($phrase, $locale, $category, null, $params);
    }

    public function client(): Client
    {
        return $this->client;
    }
}
