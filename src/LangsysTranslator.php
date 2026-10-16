<?php

namespace Langsys\Laravel;

use Langsys\Laravel\Support\ClientState;
use Langsys\SDK\Client;

/**
 * The service every integration point (helper, @t directive, facade) calls.
 * Thin by design: interpolation lives in langsys/langsys-php, whose
 * Interpolator is verified against the JS SDK's implementation, so params and
 * ICU pluralization are delegated rather than reimplemented here — a second
 * implementation would silently drift from the catalog the JS SDKs share.
 *
 * This is also the mockable seam for app tests — the SDK's cURL client is
 * concrete and non-injectable, so fake this, or bind a fake Client, instead of
 * stubbing HTTP. The facade always asks it; `__()` asks it only where a client
 * can be had — a key configured, or a bound Client that has credentials — and
 * is plain Laravel's otherwise, so a test without a key binds a fake Client.
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
     * $params is handed to the SDK rather than applied afterwards so that the
     * RAW placeholder-bearing phrase is the lookup, the one catalog entry sync
     * registers for it — building the string first (sprintf) would look up a
     * new string per runtime value, which no catalog holds.
     *
     * Everything past the locale is the SDK's, and its result is returned as
     * is. Client::translate() never throws and falls back to the interpolated
     * source phrase on every degraded path — an unreachable API, a registered
     * but untranslated phrase (WIRE-4, CAT-2) — so a fallback here could only
     * drift from the SDK's own.
     */
    public function translate(string $phrase, ?string $category = null, array $params = [], ?string $locale = null): string
    {
        // The locale this request is served in, not Client::getLocale(), which
        // auto-detects from $_SERVER and can spend an HTTP call working out the
        // project's base locale. The core reads every spelling of it as one
        // (WIRE-3).
        return $this->client->translate($phrase, ClientState::locale($locale), $category, null, $params);
    }

    /**
     * `translate()` with the provenance of what it returns (FRM-8): `catalog` for a Langsys
     * translation, `fallback` for the app's own lang-file line, `source` for the phrase itself.
     * A raw print escapes catalog text and leaves the app's own text as Laravel prints it.
     *
     * @return array{text: string, from: string}
     */
    public function resolve(string $phrase, ?string $category = null, array $params = [], ?string $locale = null): array
    {
        return $this->client->resolve($phrase, ClientState::locale($locale), $category, $params);
    }

    /**
     * A source line with inline markup, translated with only the source's own elements rebuilt
     * around the translated runs (FRM-8).
     *
     * @return array{html: string, from: string}
     */
    public function translateRich(string $sourceHtml, ?string $category = null, array $params = [], ?string $locale = null): array
    {
        return $this->client->translateRich($sourceHtml, ClientState::locale($locale), $category, $params);
    }

    /**
     * The core's currency list, named in the request's language. Like every read below, it defaults
     * to the locale this request is served in, as translate() does, rather than the core's own,
     * which auto-detects from $_SERVER. The core returns [] on an API error or without a key, so a
     * form that lists these never fails here, unless the app sets `require_credentials`, which
     * makes a missing key throw at first use.
     *
     * @return list<array{code: string, name: string, symbol: string, symbol_native: string, decimal_digits: int, rounding: int|float}>
     */
    public function getCurrencies(?string $locale = null): array
    {
        return $this->client->getCurrencies(ClientState::locale($locale));
    }

    /** @return list<array{label: string, code: string, eu: bool}> */
    public function getCountries(?string $locale = null): array
    {
        return $this->client->getCountries(ClientState::locale($locale));
    }

    /** @return list<array{country_code: string, dial_code: string, name: string}> */
    public function getDialCodes(?string $locale = null): array
    {
        return $this->client->getDialCodes(ClientState::locale($locale));
    }

    /**
     * The languages a project can target, in the core's three shapes: grouped by language (`''`),
     * one flat list (`'flat'`), or with each locale's and language's own names (`'data'`).
     */
    public function getLocalesFormat(string $format = '', ?string $locale = null): array
    {
        return $this->client->getLocalesFormat($format, ClientState::locale($locale));
    }

    public function client(): Client
    {
        return $this->client;
    }
}
