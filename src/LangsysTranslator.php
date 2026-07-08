<?php

namespace Langsys\Laravel;

use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;

/**
 * The service every integration point (helper, @t directive, facade) calls.
 * Wraps the vanilla SDK's phrase lookup and adds the interpolation layer the
 * SDK doesn't have. This is also the mockable seam for app tests — the SDK's
 * cURL client is concrete and non-injectable, so fake this (or bind a fake
 * Client) instead of stubbing HTTP.
 */
class LangsysTranslator
{
    public function __construct(
        private readonly Client $client,
        private readonly Interpolator $interpolator,
    ) {
    }

    /**
     * Translate a phrase. Mirrors the JS SDKs' t(phrase, category?, params?):
     * the phrase is both the lookup key and the base-language default, and
     * `{name}` placeholders are substituted from $params after lookup.
     */
    public function translate(string $phrase, ?string $category = null, array $params = [], ?string $locale = null): string
    {
        // Default to the app locale (set by the DetectLocale middleware), not
        // Client::getLocale() — the latter auto-detects from $_SERVER and can
        // fall back to an HTTP call for the project's base locale when unset.
        $locale ??= app()->getLocale();

        $translated = $this->client->translate($phrase, LocaleDetector::normalize($locale), $category ?? '__uncategorized__');

        return $params === [] ? $translated : $this->interpolator->interpolate($translated, $params, $locale);
    }

    public function client(): Client
    {
        return $this->client;
    }
}
