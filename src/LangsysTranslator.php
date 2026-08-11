<?php

namespace Langsys\Laravel;

use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
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
     */
    public function translate(string $phrase, ?string $category = null, array $params = [], ?string $locale = null): string
    {
        // Default to the app locale (set by the DetectLocale middleware), not
        // Client::getLocale() — the latter auto-detects from $_SERVER and can
        // fall back to an HTTP call for the project's base locale when unset.
        $locale = LocaleDetector::normalize($locale ?? app()->getLocale());

        try {
            $translated = $this->client->translate($phrase, $locale, $category ?? '__uncategorized__', null, $params);
        } catch (LangsysException $e) {
            // An unreachable or refusing API must never take the page down —
            // serve the base-language phrase and hand the failure to the
            // app's exception reporter instead of letting it become a 500.
            report($e);
            $translated = null;
        }

        // Degraded paths: the client threw, or returned null for a phrase that
        // exists but has no translation yet (the SDK's empty check is
        // `!== ''`, which lets null through). Interpolate the base phrase with
        // the SDK's own interpolator so a failure still never renders a raw
        // {name} to an end user — the guarantee the SDK gives on its own
        // fallback path.
        return $translated ?? $this->client->getInterpolator()->interpolate($phrase, $params, $locale);
    }

    public function client(): Client
    {
        return $this->client;
    }
}
