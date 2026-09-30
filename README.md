# Langsys SDK - Laravel

[![build](https://img.shields.io/github/actions/workflow/status/langsys/langsys-php-laravel/ci.yml?branch=main&style=flat)](https://github.com/langsys/langsys-php-laravel/actions/workflows/ci.yml)
[![packagist](https://img.shields.io/packagist/v/langsys/langsys-php-laravel.svg?style=flat)](https://packagist.org/packages/langsys/langsys-php-laravel)
[![downloads](https://img.shields.io/packagist/dm/langsys/langsys-php-laravel.svg?style=flat)](https://packagist.org/packages/langsys/langsys-php-laravel/stats)
[![last commit](https://img.shields.io/github/last-commit/langsys/langsys-php-laravel.svg?style=flat)](https://github.com/langsys/langsys-php-laravel/commits)
[![commit activity](https://img.shields.io/github/commit-activity/m/langsys/langsys-php-laravel.svg?style=flat)](https://github.com/langsys/langsys-php-laravel/pulse)
[![php](https://img.shields.io/packagist/dependency-v/langsys/langsys-php-laravel/php?style=flat)](https://packagist.org/packages/langsys/langsys-php-laravel)
[![laravel](https://img.shields.io/packagist/dependency-v/langsys/langsys-php-laravel/illuminate%2Fsupport?style=flat&label=laravel)](https://packagist.org/packages/langsys/langsys-php-laravel)
[![license](https://img.shields.io/packagist/l/langsys/langsys-php-laravel.svg?style=flat)](./LICENSE)

Langsys revolutionizes localization for apps with easy to integrate, realtime, continuous translations. Read more about Langsys Translation Manager [at the website](https://Langsys.dev/).

Integrate the Langsys Translation Manager into your Laravel application — Blade, Livewire, Alpine's server-rendered content, and Inertia SSR seeding for the JS SDKs.

## Requirements

- **PHP 8.1+**, **Laravel 10, 11, or 12**
- **`ext-intl`** (required — ICU plural rules and locale-aware number/date formatting). Note that the official `php:8.x-fpm` Docker images do not bundle it; add `docker-php-ext-install intl`.

> **On Laravel 10 and 11:** both lines are past Laravel's security-support window and carry unpatched advisories, so **Composer 2.9+ refuses to lock any release in them by default.** This package supports and tests both — CI runs the full suite against Laravel 10, 11 and 12 on every push — and adding it to an *existing* 10.x/11.x app works normally, because Composer blocks *locking* an advisory-flagged version, not *having* one. What it blocks is resolving the framework afresh: a new Laravel 10/11 project, or `composer update laravel/framework` within those lines. That's Laravel's advisory status rather than anything about this package, and if you hit it, `composer config audit.block-insecure false` is the (deliberate, project-wide) opt-out.

## How it's layered

`langsys/langsys-php-laravel` is a Laravel wrapper over the dependency-free [`langsys/langsys-php`](https://github.com/langsys/langsys-php), which owns the HTTP client, phrase lookup, **placeholder interpolation**, the source scanner, sync planning and catalog caching. This package adds only the Laravel-native concerns:

- **Laravel's own translate function, answered by Langsys.** `__()`, `trans()`, `trans_choice()` and `@lang` keep their calling convention and return the catalog's translation, then your lang files' translation, then the source. `t()` and `@t` are the same functions under shorter names.
- **`php artisan langsys:sync`** registers every phrase your PHP and Blade can show, and every line of your base-language files, with the translations your other lang files already have. Nothing registers while serving a request.
- A **service provider** that builds the SDK `Client` from `config/langsys.php` and routes catalog caching through **Laravel's cache** (any store — redis, memcached, file, array).
- A **`DetectLocale` middleware** resolving the request locale (query → cookie → session → `Accept-Language`) when your app has not set one.
- **Validation messages** built from the rule that failed, carried beside Laravel's own error body for a client SDK to render — see [`docs/server-messages.md`](docs/server-messages.md).
- An opt-in **`TranslateResponse` middleware** that translates whole rendered HTML responses, for routes that do not translate through `__()`.
- An **`InertiaSsrProps` helper** that seeds the JS SDKs' `initialTranslations` for SSR handoff.

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

Installing is enough: `__()` is answered by Langsys from then on. With nothing in the catalog, it returns exactly what Laravel returns on its own. `LANGSYS_ENABLED=false` turns the whole package off, for debugging with plain Laravel.

### API key permissions

- **Write key** — for `php artisan langsys:sync`, in development and in the CI job that registers phrases.
- **Read-only key** — for serving. Lookups only.

The key type is detected server-side; there is no local toggle.

## Setup

Add locale detection to your `web` group (or per route via the `langsys.locale` alias):

```php
// Laravel 11/12: bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Langsys\Laravel\Http\Middleware\DetectLocale::class,
    ]);
})
```

## Using translations

### `__()` and `@lang` — Laravel's API, unchanged

Write Laravel as you already do. Keys resolve to their line in your base-language files, and that sentence is the phrase Langsys translates:

```blade
<h1>@lang('messages.welcome', ['name' => $user->name])</h1>   {{-- lang/en/messages.php: 'Welcome back, :name' --}}
<p>{{ __('Your order ships on :date.', ['date' => $order->ships_at]) }}</p>
<p>{{ trans_choice(':count item|:count items', $count) }}</p>
```

```php
// Controllers, Livewire components, jobs, notifications — anywhere:
$title = __('Order confirmed');
```

The lookup order is the catalog, then your lang file for the locale being rendered, then the source, filled. `t()` and `@t` take the same arguments and give the same answer.

**Escaping.** `@lang` and `@t` escape catalog text: a translation can place the source line's own elements — `Read the <a href="/docs">docs</a>` keeps its link — but never add a tag or a script. A line from your own lang files prints as Laravel prints it. `{{ __() }}` escapes everything, and `{!! __() !!}` stays your raw choice.

#### Interpolation & pluralization

Laravel's `:name` becomes Langsys's `{name}`, and a `|` plural over `:count` becomes one ICU plural, so the phrase is the one every Langsys SDK renders. Replacements travel as params and fill the translated sentence, so one catalog entry serves every value.

**Replacements carry values, never translatable text.** `__('The :thing was deleted', ['thing' => 'invoice'])` never puts "invoice" in front of a translator. Write each variant as its own sentence, or — when the value comes from a finite set, such as a status — declare the set:

```php
use Langsys\SDK\Messages\TranslatesAs;

#[TranslatesAs('status')]
enum OrderStatus: string { case Shipped = 'shipped'; case Delivered = 'delivered'; }
```

`__('Your order is :status.', ['status' => $order->status])` is then registered once per value, the word written in, and each sentence is translated whole. Declarations in `app/` are found without configuration; `langsys.value_sets` lists classes kept elsewhere, and `php artisan langsys:cache` caches the discovery.

#### Categories come from groups

A key's group is its category: `menu.home` and `repairs.home` are two catalog entries, each translated for its own context. A sentence passed as its own key has no category.

### Registering — `php artisan langsys:sync`

```bash
php artisan langsys:sync            # register
php artisan langsys:sync --dry-run  # list, register nothing
php artisan langsys:sync --strict   # also fail on anything it could not register (CI)
php artisan langsys:sync --watch    # sync again on every change (development)
```

It reads every literal `__()`, `trans()`, `trans_choice()`, `@lang` and `t()` in `app/`, `routes/` and `resources/views/` (`langsys.sync_paths` replaces them), and every line of your base-language files. A phrase already in the catalog is left alone; one your lang files translate is registered with those translations, so work already done is kept; anything else is registered alone. A call whose argument is not a literal is reported with its file and line. Every validation message your routes can send is registered beside them.

### Locale detection

`DetectLocale` tries the configured sources in order (`query`, `cookie`, `session`, `header` by default), canonicalizes the winner to BCP 47, and sets it on both the Laravel app and the Langsys client. `Accept-Language` parsing is delegated to the SDK's `LocaleDetector::fromAcceptLanguage()`, so it honours `q`-value priority (`en,es-MX;q=0.9` resolves to `en`), rejects `q=0` as "not acceptable" per RFC 7231, and fills a missing region (`en` → `en-EN`), since the Langsys API addresses translations by `xx-yy` codes. An explicit `?locale=es-ES` choice persists via cookie (or session — see `config/langsys.php`). The locale cookie is exempted from cookie encryption so client-side JS can share the preference.

### What the response gets

What `__()` returns depends on who reads the response next. A page the server renders gets the translation, and its root is marked resolved so a browser SDK does not translate it again. An Inertia page — Inertia's middleware on the route, or an Inertia visit — gets the source, because its own browser SDK translates it. A notification is translated in the recipient's `preferredLocale()`, from a queued job too. `langsys.response_kinds` decides it yourself for a route group: `auto`, `server` or `client`.

### Livewire

Nothing extra to configure: Livewire's AJAX updates run through the same `web` middleware, so `__()` inside components resolves in the page's locale. Verified end-to-end in [`tests/LivewireSupportTest.php`](https://github.com/langsys/langsys-php-laravel/blob/main/tests/LivewireSupportTest.php).

```php
class Checkout extends Component
{
    public function getTitleProperty(): string
    {
        return __('Review your order');
    }
}
```

### Automatic translation — the `langsys.translate-page` middleware

Everything above covers the text you pass through `__()`. `TranslateResponse` is the **automatic mode** — it runs the SDK's page translator over the rendered HTML, translating every text node and translatable attribute (`placeholder`, `alt`, `aria-label`, …) with no `__()` at all. It's the only way to cover text Alpine injects from a JS expression, which never becomes a DOM node you can wrap:

```blade
<span x-text="'Save changes'"></span>
<button :aria-label="open ? 'Collapse' : 'Expand'">…</button>
```

> **Pick one per route — never run automatic mode over text `__()` translated.**
> If both run, this middleware re-walks text `__()` already translated, looks the *translated* string up as a source phrase, misses, and **registers it**. A Spanish `"Guardar"` then enters the catalog every Langsys SDK shares as though it were source text. Mark any already-resolved subtree `translate="no"`.

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

The page walk is the one path that registers while serving: what it meets and the catalog lacks is queued and sent **after the response**, by the `langsys.flush` terminable middleware under PHP-FPM and at the end of every **Octane** request and **queued job**. Add `langsys.flush` beside `langsys.translate-page`.

> **If you server-render with this and hydrate with a Langsys JS SDK**, use `langsys-js-typescript` **0.6.2 or newer** (or a framework SDK built on it). Its tokenizer skips `data-langsys-phrase` with semantics matching the PHP side; earlier versions re-walk server-tokenized subtrees and split phrases at tag boundaries — `Read the <a>docs</a> now` registers as three fragments — fragmenting the shared catalog silently and putting a count in a different phrase from the noun it inflects. 0.6.0 and 0.6.1 honour the marker only partially, and fail silently when they don't.

#### Excluding a subtree

Two markers, and the difference is not cosmetic:

```blade
<pre translate="no">composer require langsys/langsys-php-laravel</pre>   {{-- nobody translates this --}}
<div data-notrans>{{ $userSuppliedHtml }}</div>                        {{-- Langsys skips it --}}
```

- **`translate="no"`** is standards HTML, so browser translation features (Chrome, Safari, Edge "translate this page") honour it too. Use it for code samples, brand names, identifiers — anything no translator, human or machine, should touch.
- **`data-notrans`** excludes the subtree from Langsys only; a reader's browser-translation is still free to act on it. Use it for content that's already resolved, or that you handle yourself.

Presence is intent for both: a bare `data-notrans` excludes, and only `="false"` or `="0"` opts back in (trimmed, case-insensitive).

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

Langsys::translate('Save', 'UI');              // a phrase under a category, outside Laravel's translator
Langsys::client()->getTranslations('es-es');   // vanilla langsys/langsys-php Client
Langsys::client()->translatePage($html);       // full-page HTML translation
```

## Configuration reference

See [`config/langsys.php`](config/langsys.php): credentials (`LANGSYS_API_KEY`, `LANGSYS_PROJECT_ID`, `LANGSYS_API_URL`), the off switch (`LANGSYS_ENABLED`), response kinds, declared value sets, sync paths, the catalog snapshot, server messages, catalog cache (Laravel store/prefix/TTL), locale-detection sources and persistence, and automatic response translation (`LANGSYS_TRANSLATE_RESPONSE`). `LANGSYS_API_URL` also points the SDK at a local test double; it is read when the client is first resolved, so set it before anything translates.

## Testing your app

Bind a fake client so tests never hit the API:

```php
$this->app->instance(\Langsys\SDK\Client::class, $yourFakeClient);
```

This package's own suite shows a complete `FakeClient` pattern in [`tests/Fakes/FakeClient.php`](https://github.com/langsys/langsys-php-laravel/blob/main/tests/Fakes/FakeClient.php). Tests are excluded from the released archive, so read it in the repository rather than under `vendor/`.

## License

MIT © Langsys
