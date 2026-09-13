# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`langsys/langsys-php-laravel` — a **Laravel wrapper** over the dependency-free [`langsys/langsys-php`](https://github.com/langsys/langsys-php) (sibling checkout at `../langsys-php-sdk`, pinned `^1.3`). The vanilla SDK owns the HTTP client, phrase lookup, **interpolation**, token discovery/queueing, and catalog caching. This package adds **only** Laravel-native concerns. When a behavior belongs to lookup/HTTP/interpolation/queueing, the fix goes upstream in `langsys-php`, not here.

**Do not reimplement upstream behavior in this repo.** The wrapper previously carried its own `Interpolator` and its own `Accept-Language` parser; both drifted from — and in the parser's case reproduced a bug already fixed in — the upstream originals. Delegate and pass through. Before inventing a mechanism, check whether the SDK (or plain HTML) already has one: the skip marker proposed for `TranslateResponse` turned out to be `translate="no"`, which both SDKs already honour.

Sibling SDKs sharing the same phrase catalog and `t()` semantics live alongside this repo: `../langsys-js-typescript` (the base JS SDK this package's `Interpolator` is ported from), `../langsys-js-vue`, `../langsys-js-react`, `../langsys-js-svelte`.

## Commands

```bash
composer install                       # vendor/ is gitignored; required before anything
composer test                          # = phpunit, whole suite
vendor/bin/phpunit tests/DetectLocaleTest.php
vendor/bin/phpunit --filter testEveryBoundaryFlushesAndThenResets
```

There is no linter or static-analysis config in this repo.

`ext-intl` is a hard requirement (upstream v1.0.0), so `composer install` refuses without it. On Homebrew PHP 8.5 (built `--disable-intl`) neither `pecl install intl` nor the formula provides it — build `ext/intl` from the matching PHP source with `PKG_CONFIG_PATH` pointing at `icu4c@78`.

**Never mute Laravel's error handler to silence SDK warnings.** Upstream's runtime-requirement warning used `trigger_error`, which `HandleExceptions` escalated into a thrown `ErrorException`; the fix belonged upstream (v1.0.1 switched to `error_log()`), not in a wrapper-side suppression that would swallow unrelated warnings. If SDK behavior changes meaning under Laravel again, report it upstream — that class of bug is invisible from inside `langsys-php`.

## Architecture

The call path for every translation is: **`t()` / `@t` → `LangsysTranslator` → SDK `Client` → `Interpolator`.**

- `src/helpers.php` — global `t($phrase, $category?, $params?, $locale?)`, guarded by `function_exists`. Autoloaded via composer `files`.
- `src/LangsysServiceProvider.php` — wires everything: builds the `Client` singleton from `config/langsys.php` with a `LaravelCacheAdapter`, compiles `@t` to `e(t(...))`, registers the `langsys.locale` / `langsys.flush` / `langsys.translate-page` middleware aliases, exempts the locale cookie from `EncryptCookies`, and ends the request scope at every long-lived boundary (Octane `RequestTerminated`, queue `JobProcessed` / `JobExceptionOccurred`).
- `src/LangsysTranslator.php` — **the single mockable seam.** The SDK's cURL layer is concrete and non-injectable, so app tests fake this or bind a fake `Client`; never stub HTTP.
- `src/Support/LocaleFormatter.php` — canonical BCP 47 (`es-ES`), for **Laravel's own locale store only** (`app()->setLocale()`). Both SDKs identify a locale by lowercase `xx-yy` (WIRE-3), so every SDK boundary — the Inertia hand-off included — uses `LocaleDetector::normalize()`. `Client::setLocale()` normalizes; `Client::translate()` and `getTranslations()` do **not**, and key their catalog by the string they are handed, so the wrapper normalizes before those calls. Getting these two forms backwards is the recurring bug in this codebase.

### Interpolation belongs upstream

`$params` is handed to `Client::translate($phrase, $locale, $category, $contentBlockId, $params)` as the **fifth argument** — never applied to the returned string. That ordering is load-bearing: the SDK queues the **raw** placeholder-bearing phrase for registration and interpolates only what it returns, so `Welcome {name}` is one catalog entry instead of one per runtime value. Interpolating wrapper-side would still render correctly, which is why `LangsysTranslatorTest` asserts on what the SDK *received*, not just the output.

The SDK interpolates on its own degraded paths too, so a failure never renders a raw `{name}` and the wrapper never calls `getInterpolator()`.

### Invariants that drive the design

- **A translation lookup must never 500 a page — and that guarantee is the SDK's.** `Client::translate()` and `translatePage()` catch every failure and degrade to source (WIRE-4), and `translate()` falls back on a null value (CAT-2). The wrapper returns what they return and adds no fallback of its own (BIND-1); tests pin that it does not swallow what the SDK lets through. The one binding-owned site is `InertiaSsrProps::share()`, because `getTranslations()` throws by design.
- **Token registration must never break a request or a worker.** `flushPendingRegistrations()` catches every failure itself and never throws; the wrapper decides only *when* it runs.
- **Long-lived workers need an explicit end of scope.** Octane workers and queue workers keep the container — and the `Client` singleton — across units of work, and never fire PHP shutdown handlers in between. At each boundary the provider flushes, then calls `resetRequestState()` (GATE-3, SRV-2); flush first, because the reset does not send the queue. It acts only on a `Client` the unit of work resolved, since building one without credentials throws. Terminable middleware covers PHP-FPM, where the process ends the scope.
- **Locale defaults to `app()->getLocale()`, not `Client::getLocale()`** — the latter auto-detects from `$_SERVER` and can trigger an HTTP call for the project's base locale.
- **`LaravelCacheAdapter::clear()` only evicts its own keys** (tracked in a `__key_index` entry), never the whole Laravel store.

### Coverage model — two modes, never both

**Tagged mode (default):** only strings wrapped in `t()` / `@t` are translated. Coverage equals your tagging.

**Automatic mode (opt-in):** the `langsys.translate-page` middleware (`TranslateResponse`) runs `translatePage()` over the rendered HTML response. Covers everything, including the Alpine dynamic-attribute text `@t` structurally cannot reach.

**A project picks one.** Running both makes the middleware re-walk `@t`-translated nodes, look the *translated* string up as a source phrase, miss, and register it — poisoning the catalog every Langsys SDK shares with translated strings posing as source text. `translate="no"` is the per-subtree escape hatch; do not invent a wrapper-side skip marker.

`TranslateResponse` decides only **whether** to call `translatePage()` and **what to hand it** — never what inside the HTML gets translated. Registration flushes after the response like tagged mode (upstream ^1.3; it was inline before). The one thing it doesn't share: PHP SSR + JS hydration requires `langsys-js-typescript` ≥0.6.2, whose tokenizer knows `data-langsys-phrase`. See `ROADMAP.md` before changing this middleware.

## Conformance

`CONFORMANCE.md` grades this package against every rule id in the SDK behaviour spec (`langsys2` `docs/sdk-spec.mdx`), re-derived from the cited blob on every write. The binding rules decide most of it: **a binding adapts shape and timing, never meaning** (BIND-1). `tests/BindingBoundaryTest.php` is the absence-probe set — token scans for capability, network and identity/rendering constructs, each with a firing control, plus the pinned public surface (BIND-6) and config surface (BIND-4). A change that trips one of those scans is a reimplementation until argued otherwise; argue it in `CONFORMANCE.md`, not by widening the scan.

**Three changes are in the code but pending the operator's ruling, not accepted:** the removal of `auto_flush`, the removal of the `TranslateResponse` page cache, and the lowercase `initialTranslationsLocale`. Do not treat them as settled or build on them. `ROADMAP.md` records the restore path for each, and `CONFORMANCE.md` records what each row becomes if one is reverted.

## Conventions

- **Private methods are prefixed with `_`** (`_registerBladeDirective`, `_simpleInterpolate`, `_fromAcceptLanguage`). Public API is not.
- Comments explain **why** — the upstream quirk, the lifecycle constraint, the cross-SDK contract being upheld. Match that density; don't add restating-the-code comments.
- Tests are Orchestra Testbench (`tests/TestCase.php` binds a `FakeClient` into the container in `setUp`), classic `testXxx()` naming, no PHPUnit attributes. `tests/Fakes/FakeClient.php` extends the real `Client` and records queued phrases + flush calls — extend it with an anonymous subclass for one-off behavior rather than adding a new fake. Where the evidence has to be the SDK's own code path, use `TestCase::offlineClient()` — the real `Client` (as `RecordingClient`), catalogs seeded into the Laravel cache it reads, the API on a closed local port.
- Every user-visible change gets a `CHANGELOG.md` entry; deferred work and design decisions go in `ROADMAP.md` so context survives between sessions.
- Do not attribute commit messages.
