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
- **Testbench suite** (39 tests) with a reusable `FakeClient` pattern for app-level testing, including `LivewireSupportTest` — a real Livewire component proving `@t` interpolation in the Livewire lifecycle and token discovery + flush on a component update (`livewire/livewire` is a dev dependency).
