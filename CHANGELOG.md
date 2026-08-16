# Changelog

All notable changes to `langsys/langsys-php-laravel` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
