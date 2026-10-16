# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`langsys/langsys-php-laravel` — a **Laravel wrapper** over the dependency-free [`langsys/langsys-php`](https://github.com/langsys/langsys-php) (sibling checkout at `../langsys-php-sdk`, pinned `^1.3`). The vanilla SDK owns the HTTP client, phrase lookup, **interpolation**, token discovery/queueing, and catalog caching. This package adds **only** Laravel-native concerns. When a behavior belongs to lookup/HTTP/interpolation/queueing, the fix goes upstream in `langsys-php`, not here.

**Do not reimplement upstream behavior in this repo.** A second copy of the core's interpolation or locale negotiation drifts from the original, and can reproduce a bug the original has already fixed. Delegate and pass through. Before inventing a mechanism, check whether the SDK (or plain HTML) already has one: `TranslateResponse` needs no skip marker of its own, because `translate="no"`, which both SDKs honour, is that marker.

Sibling SDKs sharing the same phrase catalog and `t()` semantics live alongside this repo: `../langsys-js-typescript` (the base JS SDK), `../langsys-js-vue`, `../langsys-js-react`, `../langsys-js-svelte`.

## Commands

```bash
composer install                       # vendor/ is gitignored; required before anything
composer test                          # = phpunit, whole suite
vendor/bin/phpunit tests/DetectLocaleTest.php
vendor/bin/phpunit --filter testEveryBoundaryFlushesAndThenResets
```

There is no linter or static-analysis config in this repo.

`ext-intl` is a hard requirement of the core, so `composer install` refuses without it. On Homebrew PHP 8.5 (built `--disable-intl`) neither `pecl install intl` nor the formula provides it — build `ext/intl` from the matching PHP source with `PKG_CONFIG_PATH` pointing at `icu4c@78`.

**Never mute Laravel's error handler to silence SDK warnings.** Laravel's `HandleExceptions` turns a PHP warning into a thrown `ErrorException`, so an SDK warning raised with `trigger_error` would fail the request; the core reports through `error_log()` and its logger instead. A wrapper-side suppression would swallow unrelated warnings too. If SDK behavior changes meaning under Laravel, report it upstream — that class of bug is invisible from inside `langsys-php`.

## Architecture

The call path for every translation is: **`__()` / `trans()` / `trans_choice()` / `@lang` → `Translation\CatalogTranslator` (Laravel's `translator`, extended) → `LangsysTranslator` → SDK `Client` → `Interpolator`.** `t()` is `__()` by another name. Installed, the package is always on (FRM-1); `langsys.enabled` is the one off switch, and off means plain Laravel.

- `src/helpers.php` — global `t($key, $replace, $locale)`, `__()` by another name, guarded by `function_exists`. Autoloaded via composer `files`.
- `src/Translation/CatalogTranslator.php` — Laravel's translator with Langsys installed: keys resolve through the core's `resolve()` against Laravel's own base-language files (`MigrationFiles`), a catalog miss falls back to the app's own lang file for the locale (`fallbackLine()`, the core's miss fallback), a sentence passed as its own key is converted by the core's `LegacyValue::fromCall()`, `validation.*` stays with Laravel, and an Inertia page gets the source (`Http\ResponseKind`, FRM-4).
- `src/LangsysServiceProvider.php` — wires everything: builds the `Client` singleton from `config/langsys.php` with a `LaravelCacheAdapter`, extends Laravel's `translator`, installs the validator resolver and the `AttachServerMessages` response middleware, registers the `langsys.locale` / `langsys.flush` / `langsys.translate-page` middleware aliases, exempts the locale cookie from `EncryptCookies`, builds the `Client` on an Octane worker as it starts (`WorkerStarting`), and ends the request scope at every long-lived boundary (Octane `RequestTerminated`, `TaskTerminated` and `TickTerminated`, a worker's `JobProcessed` / `JobExceptionOccurred`).
- `src/View/ValueMarker.php` + `PlaceholderNames.php` — the VAR-5 Blade emitter: a precompiler routes every `{{ }}` in visible text through `ValueMarker::value()`, which wraps it in `<!--ls:NAME-->…<!--/ls-->`, the name derived by VAR-2's shared table (the vendored `tests/Fixtures/var-naming-vectors.json`). It ships only with its readers (VAR-4).
- `src/Support/AppMessageDiscovery.php` / `ValueSetDiscovery.php` over `ClassDiscovery` — MSG-7's app messages and FRM-7's value sets, each found by a token scan of app/ over the shared `ClassDiscovery::within()` and cached by `langsys:cache`.
- `src/LangsysTranslator.php` — **the single mockable seam.** The SDK's cURL layer is concrete and non-injectable, so app tests fake this or bind a fake `Client`; never stub HTTP. The facade always asks it; `__()` asks it only where `ClientState::buildable()` says a client can be had (a key configured, or a bound `Client` with credentials), so a test with no key binds a fake `Client`.
- `src/Support/LocaleFormatter.php` — canonical BCP 47 (`es-ES`), for the locale `DetectLocale` keeps in the session or cookie, and for **Laravel's own locale store** (`app()->setLocale()`) where the app's `lang/` has no spelling of its own (`LaravelLocales::inLangPath()`, which `langsys:sync` reads by too). Both SDKs identify a locale by lowercase `xx-yy` (WIRE-3). The core normalizes every locale it is handed, so a lookup passes Laravel's spelling through; the binding writes the `xx-yy` form itself only where it hands a locale to another SDK or the wire: the Inertia hand-off (`LocaleDetector::normalize()`) and `Content-Language`.

### Interpolation belongs upstream

`$params` is handed to `Client::translate($phrase, $locale, $category, $contentBlockId, $params)` as the **fifth argument** — never applied to the returned string. That ordering is load-bearing: the SDK looks up the **raw** placeholder-bearing phrase and interpolates only what it returns, so `Welcome {name}` is one catalog entry instead of one per runtime value. Interpolated wrapper-side, the lookup would be `Welcome Sarah`, which no catalog holds; `LangsysTranslatorTest` asserts on what the SDK was *asked* as well as the output.

The SDK interpolates on its own degraded paths too, so a failure never renders a raw `{name}` and the wrapper never calls `getInterpolator()`.

### Invariants that drive the design

- **A translation lookup must never 500 a page — and that guarantee is the SDK's.** `Client::translate()` and `translatePage()` catch every failure and degrade to source (WIRE-4), and `translate()` falls back on a null value (CAT-2). The wrapper returns what they return and adds no fallback of its own (BIND-1); tests pin that it does not swallow what the SDK lets through. The binding degrades on its own only where the core throws by design or no lookup is involved: `InertiaSsrProps::share()` (`getTranslations()` throws), the provider's snapshot (one that fails to load is reported and left out), discovery (a class in app/ that cannot load is passed over), `DetectLocale` (the project's locales unreadable), `AttachServerMessages`' base-locale label, and `MessageValidator`'s entries. The exception to the rule is the app's own: with `require_credentials` on and no key, the first use throws the core's `ConfigurationException`, so every catch-all around a client call here rethrows that class rather than degrading it (loading a snapshot uses no client, and cannot raise it).
- **Token registration must never break a request or a worker.** `flushPendingRegistrations()` catches every failure itself and never throws; the wrapper decides only *when* it runs.
- **Long-lived workers need an explicit end of scope.** A queue worker keeps the container — and the `Client` singleton — across jobs; an Octane worker serves each request in a sandbox, a clone of its container, and neither fires PHP shutdown handlers in between. An Octane worker builds the `Client` as it starts, so every request's sandbox shares it and the backoff clock outlives the request (REG-8, CACHE-2). At each boundary the provider flushes, then calls `resetRequestState()` (GATE-3, SRV-2), on the container the unit of work ran in: Octane's event carries the sandbox. Flush first, because the reset drops whatever is still queued (REG-8). It acts only on a `Client` the unit of work holds — one it resolved, or under Octane the one the worker built, which every sandbox shares — and never builds one: a unit that holds none has nothing queued. A job on a connection with the sync driver is no boundary, whatever the app named it: it runs inside whatever dispatched it, which ends its own scope. Anything that runs during a request resolves through the current container (`app()`), never one captured at boot: under Octane the translator and the event listeners outlive the request they were built in. Terminable middleware covers PHP-FPM, where the process ends the scope.
- **A lookup's locale is `ClientState::locale()`, never `Client::getLocale()`** — the latter auto-detects from `$_SERVER` and can trigger an HTTP call for the project's base locale. `ClientState::locale()` is the locale the request is served in: what `DetectLocale` recorded the core made of Laravel's locale (`es` → the project's default Spanish, an unserved locale → the base), else Laravel's locale as it stands. The lang-file fallback reads Laravel's own locale for the call, so an unserved locale still prints the app's own line (FRM-1).
- **Off means plain Laravel at render time too.** `ClientState::buildable()` answers no while `langsys.enabled` is off, so every runtime site and middleware passes the request through. Compiled views are the same on and off: `@lang`/`@t`/`@choice` compile to `getHtml()`/`choiceHtml()`, which Laravel's own translator answers through a macro, and `ValueMarker::value()` decides at render, because Laravel keeps a compiled view until its template changes.
- **`LaravelCacheAdapter::clear()` only evicts its own keys** (tracked in a `__key_index` entry), never the whole Laravel store.

### Coverage model — two modes, never both

**Tagged mode (default):** only text that goes through Laravel's translate function (`__()`, `@lang`, `t()`) is translated. Coverage equals your tagging. `@lang` and `@t` print through `CatalogTranslator::getHtml()`, and `@choice` through `choiceHtml()`: catalog text is escaped, lang-file text raw, rich lines rebuilt by the core (FRM-8). Registration happens through `php artisan langsys:sync` (FRM-2), apart from a sentence written in from a declared value the last sync did not see (FRM-7), which the core sends after the response.

**Automatic mode (opt-in):** the `langsys.translate-page` middleware (`TranslateResponse`) runs `translatePage()` over the rendered HTML response. Covers everything, including the Alpine dynamic-attribute text `__()` structurally cannot reach.

**A project picks one.** Running both makes the middleware re-walk `__()`-translated nodes, look the *translated* string up as a source phrase, miss, and register it — poisoning the catalog every Langsys SDK shares with translated strings posing as source text. `translate="no"` is the per-subtree escape hatch; do not invent a wrapper-side skip marker.

`TranslateResponse` decides only **whether** to call `translatePage()` and **what to hand it** — never what inside the HTML gets translated. What the walk meets is queued and registered after the response — by `langsys.flush`, a long-lived boundary or the core's shutdown flush — while tagged mode registers nothing while serving (FRM-2), apart from a sentence written in from a declared value the last sync did not see (FRM-7). The one thing it doesn't share: PHP SSR + JS hydration requires `langsys-js-typescript` ≥0.6.2, whose tokenizer knows `data-langsys-phrase`. See `ROADMAP.md` before changing this middleware.

## Conformance

`CONFORMANCE.md` grades this package against every rule id in the SDK behaviour spec (`langsys2` `docs/sdk-spec.mdx`), re-derived from the cited blob on every write. The binding rules decide most of it: **a binding adapts shape and timing, never meaning** (BIND-1). `tests/BindingBoundaryTest.php` is the absence-probe set — token scans for capability, network and identity/rendering constructs, each with a firing control, plus the pinned public surface (BIND-6) and config surface (BIND-4). A change that trips one of those scans is a reimplementation until argued otherwise; argue it in `CONFORMANCE.md`, not by widening the scan.

**Three 1.x behaviours changed in 2.0.0 by the operator's ruling:** `auto_flush` is removed, the `TranslateResponse` page cache is removed, and `initialTranslationsLocale` is lowercase. `ROADMAP.md` records why, and `UPGRADING.md` tells 1.x users.

## Conventions

- **Private methods are prefixed with `_`** (`_registerTranslateDirectives`, `_endRequestScope`, `_pathIsInScope`). Public API is not.
- Comments explain **why** — the upstream quirk, the lifecycle constraint, the cross-SDK contract being upheld. Match that density; don't add restating-the-code comments.
- Tests are Orchestra Testbench (`tests/TestCase.php` binds a `FakeClient` into the container in `setUp`), classic `testXxx()` naming, no PHPUnit attributes. `tests/Fakes/FakeClient.php` extends the real `Client` and records what it is asked and its flush calls — extend it with an anonymous subclass for one-off behavior rather than adding a new fake. Where the evidence has to be the SDK's own code path, use `TestCase::offlineClient()` — the real `Client` (as `RecordingClient`), catalogs seeded into the Laravel cache it reads, the API on a closed local port.
- **Tests never call the production API.** `phpunit.xml` points `LANGSYS_API_URL` at a closed local port, so a client built with no URL of its own reaches nothing, and `TestCase` fails any test whose client is aimed at `api.langsys.dev` before it sends anything (`ProductionGuardTest` is the firing control). A test that needs a server uses the local API, `https://api.langsys2.test/api`, or the contract fixture.
- Every user-visible change gets a `CHANGELOG.md` entry; deferred work and design decisions go in `ROADMAP.md` so context survives between sessions.
- Do not attribute commit messages.
