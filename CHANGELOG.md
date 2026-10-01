# Changelog

All notable changes to `langsys/langsys-php-laravel` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### ⚠️ Release gate

- **Requires the 838 `langsys/langsys-php` core, which is not tagged yet.** This release calls `Client::resetRequestState()`, `resolveRequestLocale()`, `resolve()`, `translateRich()`, `markResolved()`, `useMissFallback()`, `planSync()`, the value-set declarations, the rule-object message template, the server-message API and the snapshot seam, and relies on `translate()` and `translatePage()` never throwing — none of which v1.3.1 has. `^1.3` still resolves to v1.3.1 from Packagist, so a clean install of this branch is broken until the core tags, and CI fails against it by design. At publication the constraint moves to that tag. Nothing publishes before every SDK is green on the current spec, and then everything publishes at once.

### ⚠️ Pending the operator's ruling — not accepted

These three are in the code at this tip because the binding rules call for them, but none of them is accepted. The operator rules on each before anything publishes, and any of them may be reverted; `ROADMAP.md` records the restore path for each.

- **Removal of `auto_flush` / `LANGSYS_AUTO_FLUSH` — breaking.** It never stopped a flush under PHP-FPM, because the SDK's shutdown handler flushes whatever is still queued regardless. Whether discovered phrases are registered is the SDK's decision, and a binding may not introduce configuration for it (spec BIND-4). To send registrations early, call `flushPendingRegistrations()` yourself. If it is restored, the BIND-4 row is no longer green unless the operator records a waiver.
- **Removal of `translate_response.cache` / `LANGSYS_TRANSLATE_RESPONSE_CACHE` / `LANGSYS_TRANSLATE_RESPONSE_CACHE_TTL` — breaking.** A binding does not cache lookup results (BIND-5). The cached page was keyed without the project id, so two projects on one host shared entries (CACHE-1). It also served a translation for its whole TTL after the SDK's own catalog had refreshed. It shipped disabled, and the SDK still caches the catalog. The alternative on the table: restore it, keyed by project id, as a BIND-5 waiver carrying the operator's recorded agreement.
- **`InertiaSsrProps::share()` hands `initialTranslationsLocale` as lowercase `xx-yy`** (`es-es`, was `es-ES`). Both SDKs identify a locale in that form (WIRE-3), and the JS SDK canonicalizes whatever it is handed to it, so the hand-off itself behaves the same. Only an app that reads the prop for something else sees the difference. The alternative on the table: keep `es-ES`.

### Added — server messages

Validation failures become entries a client can translate, without an application writing anything of ours (spec MSG family).

- **Each entry is built from the rule that failed**, never by reading back the rendered message: the field's label written into Laravel's own sentence, values kept outside it as `{name}` markers, so each sentence is translated whole and agrees. Laravel's wording is used verbatim, and every one of its 107 validation rules is classified; a Laravel upgrade that adds a rule or a placeholder fails the suite rather than sending an unclassified message.
- **An entry's `code` is Laravel's own rule name** — `required`, `min`, `required_if` — or, for a rule object or closure, the class Laravel records it under. It does not change with the field's type.
- **A rule object states its sentence through `Langsys\SDK\Messages\HasMessageTemplate`**: `template()` returns it with `:attribute` for the label and a `{name}` marker for each value, filled from the public property of the same name. It is listed from that template once per field, and a failing request sends the same template with its params. A rule object without it is listed from its filled message, and `--strict` fails naming the interface. Each `$fail()` of any other rule object is its own entry, carrying its own message.
- **The entries travel beside Laravel's own error body**, under `langsys.messages.response_key` (default `langsys_errors`), and are flashed to the session across a redirect. `message` and `errors` keep their shape and their text. `langsys.messages.pieces` renames an entry's pieces for a client that expects other names (`['template' => 'text']`).
- **A JSON response's entries speak the request's language (FRM-5).** Each entry's `message` is in the locale the app resolved, or the one Accept-Language negotiates against your project's locales, with `Content-Language` on the response and `Vary: Accept-Language` when it was negotiated; `template` and `params` stay the source for an SDK to render. A redirect's entries, and the Inertia prop, stay source.
- **Inertia (MSG-12):** a form that fails and redirects hands its entries to the page it redirects to, as a prop under the same key. Inertia is a dev dependency of this package only.
- **`php artisan langsys:messages`** lists every validation message your FormRequests and laravel-data requests can send, built exactly as a failing request builds it, and names what it cannot list with the fix. `--strict` fails the build on any problem; by default it reports and exits 0. Each field with no declared label is named as advice, with the name Laravel prints for it, and never fails the build. Templates register through `langsys:sync`, under `langsys.messages.category` (default `Errors`).

### Added — Laravel's translate function, answered by Langsys

- **`__()`, `trans()`, `@lang` and `trans_choice()`** are answered from the Langsys catalog, with no call site changed. A key resolves to its line in your base-language files — the app's own first, then the framework's and packages' bundled English, with `lang/vendor` overrides ahead of a package's own — and that line is the phrase, never the key; the group is the category. Laravel's `:name` becomes `{name}` and a `|` plural over `:count` becomes one ICU plural, so the phrase is the one every Langsys SDK renders. A sentence passed as its own key is converted as the call that received it reads it: `__('Hello :name', ['name' => …])` looks up `Hello {name}`, while a `:word` nobody passed and a `|` outside `trans_choice()` stay as Laravel prints them. Validation lines stay with Laravel's translator. `t()` and `@t` are `__()` and `@lang` by other names. `LANGSYS_ENABLED=false` restores plain Laravel.
- **Installing never breaks the app.** With no API key — your tests, CI, a fresh checkout — every translate call returns exactly what plain Laravel returns, and the locale middleware, the page walk and failed forms serve as they would without the package; no client is built, and the cause is logged once per process at debug. An API that cannot be reached is a catalog with nothing in it. `langsys:sync` says it needs `LANGSYS_API_KEY` and `LANGSYS_PROJECT_ID` instead of failing with a trace.

### Added

- **`php artisan langsys:sync`** registers every phrase the app can show — every literal `__()`, `trans()`, `trans_choice()`, `@lang` and `t()` in PHP and Blade, every base-language line, every declared value, every validation message — with the translations your other lang files already have, and nothing it cannot read as a literal, which it reports with its file and line. `--dry-run`, `--strict` for CI, `--watch` for development. Nothing registers while serving a request.
- **`__()` answers from the catalog, then your lang files, then the source.** With nothing in the catalog it returns exactly what Laravel does.
- **`@lang` and `@t` escape catalog text.** A translation can never add markup: a line with inline tags is rebuilt from the source's own elements around the translated words. Your own lang files' text prints as Laravel prints it; `{!! __() !!}` stays your explicit raw choice.
- **Declared value sets.** A backed enum marked `#[TranslatesAs('status')]`, or a class implementing `TranslatableValues`, is found in app/ automatically; a sentence naming one of its values is registered and translated whole, one per value. `php artisan langsys:cache` caches what was found, and `optimize` runs it.
- **A page Laravel renders in a translated locale is marked `data-ls-resolved`**, so a browser SDK on it never reads that text as source.
- **laravel-data request DTOs** are listed for their validation messages as FormRequests are. Laravel's own rule objects, such as the `Rules\Enum` laravel-data infers for an enum property, are listed from Laravel's line; a field that cannot be listed is reported, and the listing carries on.
- **`langsys:sync` lists a key built at runtime inside a literal group** — `__("messages.$key")`, or `__("validation.$key")` — as covered by that group, whose every line it registers (the validation group's per field), and `--strict` passes it. `__($key)` and `__("Hello $name")` are still reported.
- **`langsys:sync` keeps a line holding `:attribute`, `:other` or `:values` out of the catalog**: such a sentence registers only through the validation listing, once per field with its label written in, and the command says so.
- **`langsys.snapshot` (`LANGSYS_SNAPSHOT`)** seeds the client from a catalog snapshot exported from Langsys, so a render has translations with no API call; a phrase the snapshot lacks falls back to the live catalog. A snapshot that fails to load (edited by hand, or not a snapshot) is reported and skipped.

### Changed

- **The request locale follows Laravel (SRV-6).** When your app has set the locale this request — its own middleware, a user preference, anything that calls `app()->setLocale()` — that locale is used, and the Langsys client is told it in the project's form (a bare `es` becomes the project's default Spanish locale, a locale the project doesn't serve becomes its base locale). Laravel's own locale is never changed. Only when nothing has set it does `DetectLocale` resolve one, from `langsys.locale.sources` in your order, and every candidate must now be a locale your Langsys project serves, narrowed further by `langsys.locale.supported` when set. When it does resolve, the response carries `Vary: Cookie` or `Vary: Accept-Language` for what the choice depended on, so a CDN never serves one visitor's language to the next. With no usable candidate the project's base locale is served.
- **Failure handling is the SDK's alone.** `LangsysTranslator`, `TranslateResponse` and `FlushPendingRegistrations` no longer catch or fall back themselves: the SDK already catches every failure and degrades to source text, so those copies could only drift from it. A lookup failure is logged through the SDK's logger rather than reported through Laravel's exception handler.

### Fixed

- **Octane workers now end each request's scope.** The client's write decision and in-memory catalog are reset between requests (spec GATE-3, SRV-2). Before, one request's decision — and its catalog — carried into the next.
- **Queue workers flush and reset at the end of every job**, including a job that throws. Discovered phrases used to wait for the worker process to exit, and state leaked from job to job.
- **The Octane listener no longer builds a client nobody used.** It resolved the SDK client on every request — and without credentials the constructor throws, so an app that had not configured Langsys raised an error per request.
- **`InertiaSsrProps::share()` no longer throws when the API is unreachable.** It runs on every Inertia request, so an outage turned every Inertia page into a 500. It now hands no seed, and the JS SDK fetches the catalog as usual.
- **`LaravelCacheAdapter` scopes its key index to the project.** Projects sharing a store and prefix shared one index, so clearing one project's cache evicted another's catalog (CACHE-1). The constructor takes the project id as a new optional fourth argument, and the service provider passes it.
- **`langsys.cache.ttl` now takes effect.** The SDK writes its catalog without a TTL, and the adapter's default matched the interface's 3600, so the configured value was never used.

### Testing

- **`CONFORMANCE.md`** grades this package against all 129 rule ids of SDK spec 8.5.5, each row naming its evidence.
- **`BindingBoundaryTest`** — absence probes for delegated behaviour (capability, network, identity and rendering constructs), each with a firing control; the public and config surfaces are pinned.
- **`RequestScopeTest`** — every long-lived boundary (queue job finished, queue job threw, Octane request), asserting what the *next* unit of work observes on the real SDK.
- **Contract tests** run the provider-built client over real HTTP against the fleet's shared API double, `tests/contract-fixture/`, vendored byte for byte from langsys-js-typescript (tree `542f57f5`) and started per test class with Node. They read back what the server accepted: a server render's served translation and its miss registered after the response; a key that may not write registering nothing even when the world changes to accept it, with the positive control; the request locale validated against the locales the server says the project serves.
- **`tests/Fixtures/inertia/failed-form-page.json`**, written by a test, is the Inertia page object after a failed form redirect, Inertia's own `errors` beside `langsys_errors`, for the Vue binding to vendor.
- **`TestCase::offlineClient()`** — the real SDK client, catalogs seeded into the Laravel cache and the API on a closed local port, for evidence that has to be the SDK's own code path.
- Every guard above was checked by mutation: breaking it reddens a named test.

## 1.0.0 - 2026-08-16

First release. The Laravel integration for [Langsys](https://langsys.dev), wrapping the dependency-free [`langsys/langsys-php`](https://github.com/langsys/langsys-php) with the Laravel-native layer only — the vanilla SDK owns HTTP, phrase lookup, interpolation, token queueing and catalog caching.

### Requirements

- **PHP 8.1+**, **Laravel 10, 11 or 12**
- **`ext-intl`** — a hard requirement, following upstream. Note that the official `php:8.x-fpm` Docker images do not bundle it.
- **`langsys/langsys-php` ^1.3.** Earlier releases each fail quietly in a different way: v1.0.0 never interpolated the `<head>` (so `<title>` and `og:*` shipped raw `{name}` while the body looked correct), through v1.2.0 page registration blocked the response with inline HTTP, and through v1.1.0 `data-notrans` excluded nothing.
- For PHP SSR + JS hydration: **`langsys-js-typescript` ≥0.6.2**, whose tokenizer recognises `data-langsys-phrase`. Earlier versions re-walk server-tokenized subtrees and split phrases at tag boundaries, fragmenting the shared catalog silently.

### Added

- **`t()` helper and `@t` Blade directive** — `t($phrase, $category?, $params?, $locale?)`, mirroring the JS SDKs' signature so one catalog serves the whole stack. Output through `@t` is HTML-escaped. `$params` is passed *to* the SDK rather than applied afterwards, so registration queues the raw placeholder-bearing phrase and `Welcome {name}` is one catalog entry instead of one per runtime value.
- **`LangsysServiceProvider`** — builds the SDK `Client` singleton from `config/langsys.php`, routes catalog caching through Laravel's cache (any store), compiles `@t`, registers the middleware aliases, exempts the locale cookie from `EncryptCookies`, and registers the Octane `RequestTerminated` flush listener.
- **`DetectLocale` middleware** (`langsys.locale`) — resolves the request locale through a configurable source chain (query → cookie → session → `Accept-Language`), canonicalises to BCP 47 for Laravel, normalises for the SDK, and persists an explicit choice by cookie or session.
- **`FlushPendingRegistrations` terminable middleware** (`langsys.flush`) — sends newly discovered phrases to Langsys *after* the response. The Octane listener covers long-lived workers, which never fire PHP shutdown handlers between requests; both are required.
- **`TranslateResponse` middleware** (`langsys.translate-page`) — **opt-in** automatic translation of rendered HTML responses via `translatePage()`, covering every text node and translatable attribute with no tagging. This closes the one gap explicit tagging structurally cannot reach: text Alpine injects from a JS expression (`x-text="'Save changes'"`, `:aria-label="…"`) never becomes a DOM node you can wrap.
- **`InertiaSsrProps::share()`** — builds the `initialTranslations` / `initialTranslationsLocale` payload the JS SDKs consume, completing the Laravel ↔ JS SSR handoff.
- **`Langsys` facade** over `LangsysTranslator`, with `client()` access to the vanilla SDK.

### Coverage model — two modes, never both

Tagged mode (`t()` / `@t`) covers exactly what you tag. Automatic mode (`TranslateResponse`) covers everything. **A project picks one.** Running both makes the middleware re-walk `@t`-translated nodes, look the *translated* string up as a source phrase, miss, and register it — so a Spanish `"Guardar"` enters the catalog every Langsys SDK shares as though it were source text.

`TranslateResponse` is registered as an alias only and never added to a middleware group, so automatic mode cannot switch itself on for a project that tags. Mark an already-resolved subtree with `translate="no"` (standards HTML, also honoured by browser translation) or `data-notrans` (Langsys only).

### Guarantees

- **A translation lookup never 500s a page.** `LangsysException` is reported and the base-language phrase served, with params still interpolated; a `null` return from the client falls back the same way. `TranslateResponse` serves the untranslated page, and an empty translation never blanks a response body.
- **Token registration never breaks a request or a worker.** Both flush paths swallow `LangsysException`. This holds for automatic mode too — page registration queues and drains after the response, exactly as tagged mode does.
- **`LaravelCacheAdapter::clear()` only evicts its own keys**, never the whole Laravel store.
- Only `text/html` responses are touched by automatic mode; JSON, redirects, streamed and file responses pass through, which is what keeps Livewire and Inertia XHR round-trips out.

### Notes

- **`Accept-Language` resolution follows RFC 7231 via the SDK.** `q`-values decide (`en,es-MX;q=0.9` resolves to `en`), `q=0` is rejected as "not acceptable", and a bare language gains a region (`en` → `en-EN`) because the Langsys API addresses translations by `xx-yy` codes.
- **`TranslateResponse` caching ships disabled.** It is keyed by `(locale, sha1(source HTML))` rather than by route, so a page varying by user can never serve another request's translation — which also makes it useless for any page carrying a CSRF token or timestamp. Enable only for genuinely static, high-traffic HTML.
- **Automatic mode does not self-heal within one response.** Translations registered by an earlier request appear on the next page load, not the current one.
- Upstream's `custom_id` ASCII-only parity limitation does not reach this package: it never calls `translateContentBlock()`, and `InertiaSsrProps` ships the `getTranslations()` category map rather than content blocks.

### Testing

59 Orchestra Testbench tests, plus a reusable `FakeClient` pattern for application-level testing. `LivewireSupportTest` proves `@t` interpolation and token discovery across a real Livewire update. `TranslateResponseSafetyTest` runs the **real** page translator over a Laravel-shaped response — CSRF meta tag, inline bootstrapped JSON, `<style>` block — asserting that script and style bodies survive byte-for-byte and that nothing script-derived reaches the shared catalog. Its assertions read the pending-registration queue rather than rendered output, because catalog pollution is the irreversible half and surfaces there first.

Pre-release development history, including the design decisions behind the coverage model, lives in `ROADMAP.md` and the git log.
