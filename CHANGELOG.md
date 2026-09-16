# Changelog

All notable changes to `langsys/langsys-php-laravel` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### ⚠️ Release gate

- **Requires the 838 `langsys/langsys-php` core, which is not tagged yet.** This release calls `Client::resetRequestState()` and relies on `translate()` and `translatePage()` never throwing — none of which v1.3.1 has. `^1.3` still resolves to v1.3.1 from Packagist, so a clean install of this branch is broken until the core tags, and CI fails against it by design. At publication the constraint moves to that tag. Nothing publishes before every SDK is green on spec 8.0.1, and then everything publishes at once.

### ⚠️ Pending the operator's ruling — not accepted

These three are in the code at this tip because the binding rules call for them, but none of them is accepted. The operator rules on each before anything publishes, and any of them may be reverted; `ROADMAP.md` records the restore path for each.

- **Removal of `auto_flush` / `LANGSYS_AUTO_FLUSH` — breaking.** It never stopped a flush under PHP-FPM, because the SDK's shutdown handler flushes whatever is still queued regardless. Whether discovered phrases are registered is the SDK's decision, and a binding may not introduce configuration for it (spec BIND-4). To send registrations early, call `flushPendingRegistrations()` yourself. If it is restored, the BIND-4 row is no longer green unless the operator records a waiver.
- **Removal of `translate_response.cache` / `LANGSYS_TRANSLATE_RESPONSE_CACHE` / `LANGSYS_TRANSLATE_RESPONSE_CACHE_TTL` — breaking.** A binding does not cache lookup results (BIND-5). The cached page was keyed without the project id, so two projects on one host shared entries (CACHE-1). It also served a translation for its whole TTL after the SDK's own catalog had refreshed. It shipped disabled, and the SDK still caches the catalog. The alternative on the table: restore it, keyed by project id, as a BIND-5 waiver carrying the operator's recorded agreement.
- **`InertiaSsrProps::share()` hands `initialTranslationsLocale` as lowercase `xx-yy`** (`es-es`, was `es-ES`). Both SDKs identify a locale in that form (WIRE-3), and the JS SDK canonicalizes whatever it is handed to it, so the hand-off itself behaves the same. Only an app that reads the prop for something else sees the difference. The alternative on the table: keep `es-ES`.

### Added — server messages, in progress

Spec 8.1.0's MSG family, against the core's `Langsys\SDK\Messages`. Validation failures become entries a client can translate, without an application writing anything of ours.

- **`langsys.localization` (`LANGSYS_LOCALIZATION`)** picks the mode. **`keep` is the default and changes nothing**: Laravel's validator, Laravel's wording, Laravel's 422 body, and nothing sent to Langsys.
- **`migrate`** builds an entry from each rule that failed — never by reading back the rendered message — with the field's label written into Laravel's own sentence and values kept outside it as `{name}` markers, so each sentence is translated whole and agrees.
- **The entries travel beside Laravel's own error body**, under `langsys.messages.response_key` (default `langsys_errors`), and are flashed to the session across a redirect. `message` and `errors` keep their shape and their text.
- **The server never emits Langsys-translated text here.** Entries carry source text; a client renders the translation from `entry.template` and falls back to `entry.message` (MSG-5).
- **A template the catalog lacks is registered after the response**, under `langsys.messages.category` (default `Errors`).
- **Inertia (MSG-12):** a form that fails and redirects hands its entries to the page it redirects to, as a prop under the same key, so the next page can render them. Inertia is a dev dependency of this package only — nothing is added to an application that does not already use it.
- Laravel's wording is used verbatim, and every one of its 107 validation rules is classified; a Laravel upgrade that adds a rule or a placeholder fails the suite rather than sending an unclassified message.

Not built yet: `__()` and `trans()` in migrate mode, `fill` mode, and the `langsys:messages` command.

### Changed

- **Failure handling is the SDK's alone.** `LangsysTranslator`, `TranslateResponse` and `FlushPendingRegistrations` no longer catch or fall back themselves: the SDK already catches every failure and degrades to source text, so those copies could only drift from it. A lookup failure is logged through the SDK's logger rather than reported through Laravel's exception handler.

### Fixed

- **Octane workers now end each request's scope.** The client's write decision and in-memory catalog are reset between requests (spec GATE-3, SRV-2). Before, one request's decision — and its catalog — carried into the next.
- **Queue workers flush and reset at the end of every job**, including a job that throws. Discovered phrases used to wait for the worker process to exit, and state leaked from job to job.
- **The Octane listener no longer builds a client nobody used.** It resolved the SDK client on every request — and without credentials the constructor throws, so an app that had not configured Langsys raised an error per request.
- **`InertiaSsrProps::share()` no longer throws when the API is unreachable.** It runs on every Inertia request, so an outage turned every Inertia page into a 500. It now hands no seed, and the JS SDK fetches the catalog as usual.
- **`LaravelCacheAdapter` scopes its key index to the project.** Projects sharing a store and prefix shared one index, so clearing one project's cache evicted another's catalog (CACHE-1). The constructor takes the project id as a new optional fourth argument, and the service provider passes it.
- **`langsys.cache.ttl` now takes effect.** The SDK writes its catalog without a TTL, and the adapter's default matched the interface's 3600, so the configured value was never used.

### Testing

- **`CONFORMANCE.md`** grades this package against all 79 rule ids of SDK spec 8.0.1, each row naming its evidence.
- **`BindingBoundaryTest`** — absence probes for delegated behaviour (capability, network, identity and rendering constructs), each with a firing control; the public and config surfaces are pinned.
- **`RequestScopeTest`** — every long-lived boundary (queue job finished, queue job threw, Octane request), asserting what the *next* unit of work observes on the real SDK.
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
