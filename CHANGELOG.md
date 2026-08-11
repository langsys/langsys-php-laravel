## Unreleased

Migration onto `langsys/langsys-php` v1.0.0, plus the package rename. Nothing here has shipped to Packagist yet, so no consumer upgrade path is required.

### Changed

- **Renamed to `langsys/langsys-php-laravel`** (was `langsys/laravel-sdk`), matching the upstream `langsys/langsys-php` convention. The GitHub repository moved from `langsys/langsys-laravel` to `langsys/langsys-php-laravel`.
- **Depends on `langsys/langsys-php: ^1.0`** (was `langsys/php-sdk: dev-main`). Upstream renamed the package and tagged v1.0.0; the old name no longer resolves, so the previous constraint could not install at all. The custom VCS `repositories` block is gone — the package resolves from Packagist — and `minimum-stability` is back to `stable`.
- **BREAKING: `ext-intl` is now required**, following upstream. It was previously listed under `suggest`. The official `php:8.x-fpm` Docker images do not bundle it.
- **Interpolation is delegated to the SDK.** `src/Interpolator.php` and its 11 tests are **deleted**; `LangsysTranslator` now passes `$params` to `Client::translate()` as its v1.0.0 fifth argument. The wrapper's hand-port of the JS `interpolate()` had become a second implementation of a contract shared with the JS SDKs, and upstream's is verified against the JS SDK's own output. This also fixes catalog pollution the wrapper could not: registration now queues the **raw** placeholder-bearing phrase, so `Welcome {name}` is one catalog entry rather than one per runtime value.
- **`DetectLocale` delegates `Accept-Language` parsing to `LocaleDetector::fromBrowser()`.** The middleware's own parser reproduced the exact defect upstream fixed in v1.0.0 — two code paths that disagreed depending on whether `ext-intl` was loaded, so the same visitor could be served a different language on two identical deployments. Two behavior changes follow from adopting it: `q`-values now decide (`en,es-MX;q=0.9` → `en`, previously `es-MX`), and a bare language gains a region (`en` → `en-EN`), since the Langsys API addresses translations by `xx-yy` codes.
- `LangsysTranslator` no longer takes an `Interpolator` constructor argument, and the provider no longer binds one. It uses `Client::getInterpolator()` on its degraded paths so an API failure still never renders a raw `{name}`.
- **`DetectLocale` passes the request header to `LocaleDetector::fromAcceptLanguage()`** (added in upstream v1.0.1 at this package's request) instead of bridging `$_SERVER` into `fromBrowser()`. A header rewritten by earlier middleware is now honoured, and test-kernel requests behave like real ones.

### Fixed upstream (requires `langsys/langsys-php` ^1.0.1)

- **A missing `ext-intl` no longer breaks the render under Laravel.** Upstream warned via `trigger_error(E_USER_WARNING)`, which Laravel's `HandleExceptions` converts into a thrown `ErrorException` — so `Client` construction *threw* on hosts without the extension, inverting upstream's intent that it degrade. Found here and fixed upstream in v1.0.1 by switching to `error_log()`, which no framework error handler can intercept. Upstream also added a regression test installing a throwing handler. A `warn_runtime_requirements => false` option now exists to silence that leg, which this package does not need.
- **`q=0` is now correctly rejected** in `Accept-Language` (RFC 7231: "not acceptable"). Previously `de;q=0` selected German; it now falls through to the next source. Malformed (`q=abc`) and out-of-range (`q=1.5`) values are discarded rather than being promoted to `q=1`. Covered by `DetectLocaleTest`.
- **The SDK's ICU calls could throw `IntlException`** (`@` does not suppress exceptions), which mattered here because `Client::getInterpolator()` is used precisely on the degraded paths that promise never to fail. Guarded upstream.

### Verified

- **Differential test against the deleted `Interpolator`** — the removed hand-port and upstream's implementation were compared across 26 cases (unknown keys, nulls, string opt-out of number formatting, bool rendering, ICU plural/select including Russian's `few`, malformed ICU fallback, locale-specific number and date formatting). **Zero disagreements**, confirming the deletion changed no behavior.

## 0.1.0 - Unreleased

Initial release. `langsys/laravel-sdk` wraps the dependency-free [`langsys/php-sdk`](https://github.com/langsys/langsys-php) with the Laravel-native integration layer for server-rendered stacks (Blade, Livewire, Alpine server-rendered content) plus Inertia SSR seeding for the JS SDKs.

### Added

- **`LangsysServiceProvider`** — builds the SDK `Client` singleton from `config/langsys.php`, injects a `LaravelCacheAdapter` so the translation catalog lives in the app's configured cache store, registers the `@t` directive and middleware aliases, exempts the locale cookie from cookie encryption, and flushes the pending-registration queue after every Octane request (long-lived workers never fire PHP shutdown handlers between requests).
- **`t()` helper + `@t` Blade directive** — `t($phrase, $category?, $params?, $locale?)`, mirroring the JS SDKs' signature; output is HTML-escaped.
- **`Interpolator`** — port of the base JS SDK's `interpolate()`: `{name}` substitution with unknown placeholders left visible, CLDR number/date formatting per target locale, and ICU MessageFormat (plural/select) via `ext-intl` with graceful fallback. The vanilla PHP SDK has no interpolation — this is where params and pluralization live on the PHP side.
- **`DetectLocale` middleware** — configurable source chain (query → cookie → session → `Accept-Language`), canonical BCP 47 output for Laravel (`LocaleFormatter`), SDK-normalized locale for the client, cookie/session persistence of explicit choices.
- **`FlushPendingRegistrations` terminable middleware** — sends write-key-discovered phrases to Langsys after the response; never breaks a request over registration failures.
- **`InertiaSsrProps::share()`** — builds the `initialTranslations` / `initialTranslationsLocale` payload the JS SDKs' `LangsysApp.init()` consumes, completing the Laravel ↔ JS SSR handoff.
- **`Langsys` facade** over the `LangsysTranslator` service, with `client()` access to the vanilla SDK.
- **API-failure resilience** — `LangsysTranslator` catches `LangsysException` from the SDK (timeouts, 404 on an unseeded project, revoked keys) and degrades to the base-language phrase (params still interpolated), reporting the exception through the app's handler instead of letting a translation lookup 500 the page.
- **Testbench suite** (39 tests) with a reusable `FakeClient` pattern for app-level testing, including `LivewireSupportTest` — a real Livewire component proving `@t` interpolation in the Livewire lifecycle and token discovery + flush on a component update (`livewire/livewire` is a dev dependency).
