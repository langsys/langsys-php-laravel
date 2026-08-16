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

### Added

- **`TranslateResponse` middleware** (`langsys.translate-page`) — opt-in automatic translation of rendered HTML responses via the SDK's `translatePage()`, covering every text node and translatable attribute with no `@t` tagging. This closes the one gap explicit tagging structurally cannot reach: text Alpine injects from a JS expression (`x-text="'Save changes'"`, `:aria-label="…"`) never becomes a DOM node you can wrap. Configured under `translate_response` in `config/langsys.php`.
  - **Automatic and tagged mode must not be combined — one mode per project.** If both run, the middleware re-walks nodes `@t` already translated, looks the *translated* string up as a source phrase, misses, and registers it, so a Spanish `"Guardar"` enters the catalog every Langsys SDK shares as though it were source text. `translate="no"` marks an already-resolved subtree; no wrapper-side skip marker was invented, because that capability already exists in standards HTML and both SDKs honour it.
  - Registered as an alias only, never added to a middleware group, so automatic mode cannot switch itself on for a project that tags with `@t`.
  - Only `text/html` responses are touched; JSON, redirects, streamed and file responses pass through. The content-type guard is what excludes Livewire and Inertia XHR round-trips without the middleware knowing those libraries exist.
  - Degrades like every other lookup path here: a `LangsysException` is reported and the **untranslated** page served, and a client returning an empty string never blanks a response body.
  - Optional caching keyed by `(locale, sha1(source HTML))` — never by route, so a page varying by user can't serve another request's translation. Ships **disabled**, because a CSRF token or timestamp changes the hash every render.
  - **Verified against the real page translator**, not the test fake (`tests/TranslateResponseSafetyTest.php`): inline `<script>`/`<style>` bodies survive byte-for-byte, both copies of a CSRF token are intact, nothing script-ish is registered to the shared catalog, and `translate="no"` is honoured. This wrapper is what feeds `translatePage()` whole rendered pages, so a fragment-level test would not have exercised the risk.

### Known behaviour

- **Automatic mode with a write key is development-only.** `translatePage()` does not use the pending-registration queue that `translate()` uses — it calls `canWrite()`, then `registerPhrases()`, then `clearCache()` + `getTranslations()` to refetch, all inline mid-render and all over HTTP. `FlushPendingRegistrations` and the Octane listener therefore do not govern automatic mode, and a page containing new phrases makes three blocking calls before the response is sent. Failures are swallowed, so the cost is latency rather than correctness; read-only keys skip the path entirely. Upstream has confirmed this is a coupling rather than a constraint (the refetch cannot surface translations for the items it is sequenced against, since those were only just registered) and is weighing moving page registration onto the pending queue.
- **PHP SSR + JS hydration requires `langsys-js-typescript` 0.6.2+**, whose tokenizer skips `data-langsys-phrase` with semantics matching the PHP side. Earlier versions re-walk server-tokenized subtrees and split phrases at tag boundaries, fragmenting the shared catalog silently; 0.6.0 and 0.6.1 honour the marker only partially and fail silently when they don't. 0.6.2 is published, so the requirement is satisfiable.
- **Both exclusion markers are now documented** (requires `langsys/langsys-php` ^1.2). `translate="no"` is standards HTML, so browser translation features honour it too; `data-notrans` excludes from Langsys only, leaving browser translation free to act. Presence is intent for both, with `="false"`/`="0"` opting back in.

  `data-notrans` was deliberately left undocumented until now: through v1.1.0 its semantics were inverted — measured here, bare `data-notrans` was **registered into the shared catalog** while both `="true"` and `="false"` excluded, so no string an author could write opted back in. Fixed in v1.2.0; ten marker cases are now pinned in `TranslateResponseSafetyTest`, asserted on the registration list rather than rendered output.

### Changed (adopting `langsys/langsys-php` v1.2.0)

- **Pinned `^1.2`**, which fixes `data-notrans` (above) and makes `translate="no"` case-insensitive. Note for consumers: v1.2.0 also changes a bare or empty `data-langsys-contentblock` from a no-op into an *enabled* marker, so all three markers follow one rule — if you were emptying that attribute to disable it, remove it instead. Nothing in this package used it.

### Changed (adopting `langsys/langsys-php` v1.1.0)

- **Pinned `^1.1.0`.** No API changes were required — `translatePage()`'s signature is stable and nothing this package calls moved. Two upstream fixes in that release land on code paths the wrapper uses: an ICU value that itself looked like ICU could recurse until memory was exhausted on the no-intl path (reachable through `Client::getInterpolator()`, which this package calls precisely on its degraded paths), and a pre-release `data-langsys-phrase` bug that encoded inline `<script>`/`<style>` bodies into registered phrases.
- **The second ICU defect reported from here is fixed upstream.** Without `ext-intl`, v1.0.1 emitted raw MessageFormat source into the page (`{n, plural, one {# item} other {# items}}`) rather than degrading. v1.1.0 renders a readable sentence: exact `=N` branch, then `one` for a value of 1, then `other`, with `#` substituted. Re-verified here under `php -n` — English now resolves correctly (`1 item` / `5 items`), and Russian degrades to the `other` branch (`3 товаров` rather than CLDR-correct `товара`), which is prose instead of markup.

### Not applicable to this package

- Upstream's known `custom_id` limitation — content-block ids agree with the JS SDKs for ASCII only, because JS `md5` hashes UTF-16 code units where PHP hashes UTF-8 bytes — **does not reach this wrapper.** It never calls `translateContentBlock()`, always passes `$contentBlockId = null`, and `InertiaSsrProps` ships the `getTranslations()` category → phrase → translation map rather than content blocks. Revisit if the deferred `TranslateResponse` middleware ever introduces content blocks.
- Upstream's no-`ext-intl` ICU apostrophe-quoting limitation (a translation using `'{'` as a literal emits the pattern verbatim) is unreachable here, since `ext-intl` is a hard requirement.

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
