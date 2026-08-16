# Langsys SDK - Laravel

Langsys revolutionizes localization for apps with easy to integrate, realtime, continuous translations. Read more about Langsys Translation Manager [at the website](https://Langsys.dev/).

Integrate the Langsys Translation Manager into your Laravel application — Blade, Livewire, Alpine's server-rendered content, and Inertia SSR seeding for the JS SDKs.

## Requirements

- **PHP 8.1+**, **Laravel 10, 11, or 12**
- **`ext-intl`** (required — ICU plural rules and locale-aware number/date formatting). Note that the official `php:8.x-fpm` Docker images do not bundle it; add `docker-php-ext-install intl`.

## How it's layered

`langsys/langsys-php-laravel` is a Laravel wrapper over the dependency-free [`langsys/langsys-php`](https://github.com/langsys/langsys-php) — which owns the HTTP client, phrase lookup, **placeholder interpolation**, token discovery/queueing, and catalog caching. This package adds only the Laravel-native concerns:

- A **service provider** that builds the SDK `Client` from `config/langsys.php` and routes catalog caching through **Laravel's cache** (any store — redis, memcached, file, array)
- A **`t()` helper and `@t` Blade directive** exposing the SDK's `{name}` interpolation and ICU pluralization to Blade — the same phrase syntax as the Langsys JS SDKs, so one catalog serves your whole stack
- A **`DetectLocale` middleware** resolving the request locale (query → cookie → session → `Accept-Language`)
- A **`FlushPendingRegistrations` terminable middleware** (plus an Octane listener) that sends newly discovered phrases to Langsys *after* the response
- An opt-in **`TranslateResponse` middleware** that translates whole rendered HTML responses, for projects that want coverage without hand-tagging
- An **`InertiaSsrProps` helper** that seeds the JS SDKs' `initialTranslations` for SSR handoff

## Install

```bash
composer require langsys/langsys-php-laravel
php artisan vendor:publish --tag=langsys-config
```

Set your credentials in `.env`:

```dotenv
LANGSYS_API_KEY=your-api-key
LANGSYS_PROJECT_ID=your-project-id
```

### API key permissions

- **Write key** (development): phrases rendered through `t()` / `@t` that aren't in the catalog yet are queued and auto-registered at the end of the request.
- **Read-only key** (production): lookups only — no token creation.

The key type is detected server-side; there is no local toggle.

## Setup

Add the middleware to your `web` group (or per-route via the `langsys.locale` / `langsys.flush` aliases):

```php
// Laravel 11/12: bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Langsys\Laravel\Http\Middleware\DetectLocale::class,
        \Langsys\Laravel\Http\Middleware\FlushPendingRegistrations::class,
    ]);
})
```

## Using translations

### `t()` and `@t` — the everyday API

The signature mirrors the JS SDKs: **`t($phrase, $category?, $params?, $locale?)`**. The phrase is both the lookup key and the base-language default — no keys file.

```blade
<h1>@t('Welcome to my app', 'UI')</h1>
<p>@t('Hello, {name}! You have {count} new messages.', 'Greetings', ['name' => $name, 'count' => $count])</p>
```

```php
// Controllers, Livewire components, jobs, mail — anywhere:
$title = t('Order confirmed', 'Checkout');
$body  = t('Your order {id} ships on {date}.', 'Checkout', ['id' => (string) $order->number, 'date' => $order->ships_at]);
```

Output through `@t` is HTML-escaped like `{{ }}`.

#### Interpolation & pluralization

`{name}` placeholders use the **same syntax as the JS SDKs**, so the same phrase translates once and renders everywhere. Numbers and `DateTimeInterface` values are CLDR-formatted for the target locale (pass strings to opt out), and ICU MessageFormat pluralization gives correct categories per language:

```php
t('{count, plural, one {# item} other {# items}}', 'Cart', ['count' => $count]);
```

Unknown placeholders are left visible rather than rendering empty.

**Always pass values as `$params` — never build the string first.** `t(sprintf('Hello, %s!', $name))` registers a brand-new catalog phrase for every distinct runtime value, polluting the catalog that every Langsys SDK shares. Passing `$params` keeps one entry (`Hello, {name}!`) serving every value: the raw placeholder-bearing phrase is what gets registered, and only what you receive is interpolated.

Interpolation itself lives in `langsys/langsys-php`, so Blade, plain PHP, and the JS SDKs all render a shared phrase identically.

#### Categorization disambiguates context

```blade
<strong>@t('Home', 'Main Menu')</strong>      {{-- "Inicio" --}}
<strong>@t('Home', 'Home repairs')</strong>   {{-- "Hogar" --}}
```

### Locale detection

`DetectLocale` tries the configured sources in order (`query`, `cookie`, `session`, `header` by default), canonicalizes the winner to BCP 47, and sets it on both the Laravel app and the Langsys client. `Accept-Language` parsing is delegated to the SDK's `LocaleDetector::fromAcceptLanguage()`, so it honours `q`-value priority (`en,es-MX;q=0.9` resolves to `en`), rejects `q=0` as "not acceptable" per RFC 7231, and fills a missing region (`en` → `en-EN`), since the Langsys API addresses translations by `xx-yy` codes. An explicit `?locale=es-ES` choice persists via cookie (or session — see `config/langsys.php`). The locale cookie is exempted from cookie encryption so client-side JS can share the preference.

### Token discovery & flushing

With a write key, phrases that miss the catalog are queued in-memory during the request and flushed to Langsys **after the response is sent** — by the terminable middleware under PHP-FPM, and by a `RequestTerminated` listener under **Octane** (where PHP's shutdown handlers never fire between requests). Read-only keys skip the flush silently. Disable with `LANGSYS_AUTO_FLUSH=false` and flush manually via `app(\Langsys\SDK\Client::class)->flushPendingRegistrations()`.

### Livewire

Nothing extra to configure: Livewire's AJAX updates run through the same `web` middleware, so `t()` inside components resolves in the page's locale and newly discovered phrases flush after every update. Verified end-to-end in `tests/LivewireSupportTest.php` — a real component renders `@t` with interpolation, and a phrase that only appears after an interaction is discovered on the Livewire update and drained by the flush middleware.

```php
class Checkout extends Component
{
    public function getTitleProperty(): string
    {
        return t('Review your order', 'Checkout');
    }
}
```

### Automatic translation — the `langsys.translate-page` middleware

Everything above is **tagged mode**: coverage equals your tagging. `TranslateResponse` is the **automatic mode** — it runs the SDK's page translator over the rendered HTML, translating every text node and translatable attribute (`placeholder`, `alt`, `aria-label`, …) with no `@t` at all. It's the only way to cover text Alpine injects from a JS expression, which never becomes a DOM node you can wrap:

```blade
<span x-text="'Save changes'"></span>
<button :aria-label="open ? 'Collapse' : 'Expand'">…</button>
```

> **Pick one mode per project — never run automatic mode and `@t` together.**
> If both run, this middleware re-walks nodes `@t` already translated, looks the *translated* string up as a source phrase, misses, and **registers it**. A Spanish `"Guardar"` then enters the catalog every Langsys SDK shares as though it were source text. Mark any already-resolved subtree `translate="no"`.

It is opt-in and applies to nothing until you attach it:

```php
// routes/web.php — per route or group, never global
Route::middleware('langsys.translate-page')->group(function () {
    Route::get('/', HomeController::class);
});
```

```dotenv
LANGSYS_TRANSLATE_RESPONSE=true
```

Only `text/html` responses are touched. JSON, redirects, streamed and file responses pass through untouched — which is what keeps Livewire and Inertia XHR round-trips out automatically. Scope it further with `only` / `except` path patterns in `config/langsys.php` (`except` wins), and set a `category` to namespace everything the page registers.

**Use a read-only key in production with this middleware.** Unlike `t()` / `@t` — whose newly discovered phrases are queued and flushed *after* the response — `translatePage()` registers inline, mid-render: a permissions check, the registration POST, and a catalog refetch, all blocking before the response is sent. On a write key a page containing new phrases pays all three. Failures are swallowed, so it costs latency rather than correctness, and read-only keys skip the path entirely.

**Caching is off by default and should usually stay off.** Translated output is keyed by a hash of the source HTML, so a page carrying a CSRF token or a timestamp produces a new key every render. Enable it only for genuinely static, high-traffic HTML.

> **If you server-render with this and hydrate with a Langsys JS SDK**, use `langsys-js-typescript` **0.6.2 or newer** (or a framework SDK built on it). Its tokenizer skips `data-langsys-phrase` with semantics matching the PHP side; earlier versions re-walk server-tokenized subtrees and split phrases at tag boundaries — `Read the <a>docs</a> now` registers as three fragments — fragmenting the shared catalog silently and putting a count in a different phrase from the noun it inflects. 0.6.0 and 0.6.1 honour the marker only partially, and fail silently when they don't.

To exclude a subtree from automatic translation, use **`translate="no"`** — standards HTML, honoured by both SDKs.

### Inertia SSR seeding (Vue/React/Svelte SDKs)

Hand the server-fetched catalog to the JS SDK so the client skips its initial fetch:

```php
// app/Http/Middleware/HandleInertiaRequests.php
use Langsys\Laravel\Support\InertiaSsrProps;

public function share(Request $request): array
{
    return [...parent::share($request), ...InertiaSsrProps::share()];
}
```

```typescript
// resources/js — Vue example (same shape for React/Svelte)
import { LangsysApp, useLocaleStore } from 'langsys-js-vue';

const { store } = useLocaleStore(props.langsys.initialTranslationsLocale);
LangsysApp.init({
    projectid: import.meta.env.VITE_LANGSYS_PROJECT_ID,
    key: import.meta.env.VITE_LANGSYS_API_KEY, // read-only key on the client
    UserLocaleStore: store,
    initialTranslations: props.langsys.initialTranslations,
    initialTranslationsLocale: props.langsys.initialTranslationsLocale,
});
```

### The facade and the raw client

```php
use Langsys\Laravel\Facades\Langsys;

Langsys::translate('Save', 'UI');
Langsys::client()->getTranslations('es-es');   // vanilla langsys/langsys-php Client
Langsys::client()->translatePage($html);       // full-page HTML translation
```

## Configuration reference

See [`config/langsys.php`](config/langsys.php): credentials (`LANGSYS_API_KEY`, `LANGSYS_PROJECT_ID`, `LANGSYS_API_URL`), catalog cache (Laravel store/prefix/TTL), locale-detection sources and persistence, and `auto_flush`.

## Testing your app

Bind a fake client so tests never hit the API:

```php
$this->app->instance(\Langsys\SDK\Client::class, $yourFakeClient);
```

This package's own suite (`composer test`) shows a complete `FakeClient` pattern in `tests/Fakes/FakeClient.php`.

## License

MIT © Langsys
