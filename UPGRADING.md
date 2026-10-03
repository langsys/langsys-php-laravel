# Upgrading

## From 1.x to 2.0

2.0 makes Laravel's own translate function the Langsys one: `__()`, `trans()`, `trans_choice()` and `@lang` are answered from the catalog, then your lang files, then the source, and phrases are registered by `php artisan langsys:sync` instead of while pages render. Most applications change their `t()` calls, their deploy step, and a few config keys. Each step below says what changed and what to do.

### Requirements

- **The matching `langsys/langsys-php` release.** Composer installs it with this package.
- PHP 8.1+, Laravel 10, 11 or 12, and `ext-intl`, as in 1.x.

### `t()` and `@t` are `__()` and `@lang`

In 1.x, `t($phrase, $category, $params, $locale)` took a Langsys phrase with `{name}` placeholders and a category. In 2.0, `t()` is `__()` by another name — `t($key, $replace, $locale)` — and `@t` is `@lang`.

```php
// 1.x
t('Hello {name}!', 'Greetings', ['name' => $user->name]);

// 2.0
__('Hello :name!', ['name' => $user->name]);
```

- **Placeholders are Laravel's.** Write `:name`; Langsys registers and looks up `{name}`, the same phrase every Langsys SDK uses.
- **Plurals are Laravel's.** `trans_choice(':count item|:count items', $n)` becomes one ICU plural over `count`.
- **Categories come from groups.** A key's group is its category: put context-dependent phrases in a lang group file (`lang/en/menu.php` → `__('menu.home')`) and they are registered and looked up under that group. A sentence passed as its own key has no category. A 1.x phrase registered under a category is a different catalog entry from the same sentence with none, so it is translated again unless you move it into the matching group.
- **Escaping.** `@lang` and `@t` escape catalog text and rebuild a line with inline markup from the source's own elements; text from your own lang files prints as Laravel prints it. `{{ __() }}` escapes everything.
- **Outside Laravel's translator**, `Langsys::translate($phrase, $category, $params)` still looks a phrase up under a category, as 1.x's `t()` did. `langsys:sync` does not read those calls.

### Registration happens in `langsys:sync`, not at runtime

In 1.x, a write key registered every phrase a page rendered that the catalog lacked, after the response. In 2.0, serving a request registers nothing. Run

```bash
php artisan langsys:sync
```

with a write key — in CI, or as a deploy step — and serve with a read-only key. It registers every literal `__()`, `trans()`, `trans_choice()`, `@lang` and `t()` in your PHP and Blade, every line of your base-language files with the translations your other lang files already have, and every validation message your routes can send. `--strict` fails the run on any call it cannot read as a literal; `--watch` syncs again on every change during development. The opt-in page walk (`langsys.translate-page`) still registers what it meets after the response.

### Configuration

Republish the config (`php artisan vendor:publish --tag=langsys-config --force`), or edit yours:

- **Remove `auto_flush` (`LANGSYS_AUTO_FLUSH`).** Under PHP-FPM it never stopped a flush — the SDK sends its queue at shutdown regardless — and what little is queued at runtime in 2.0 is always sent after the response.
- **Remove `translate_response.cache` (`LANGSYS_TRANSLATE_RESPONSE_CACHE`, `LANGSYS_TRANSLATE_RESPONSE_CACHE_TTL`).** The page walk no longer caches pages: the SDK caches the catalog, and a cached page carried a stale translation for its TTL and was shared between projects on one store.
- **New keys**, all optional: `enabled` (`LANGSYS_ENABLED`, the one off switch), `response_kinds`, `value_sets`, `sync_paths`, `snapshot` (`LANGSYS_SNAPSHOT`), and `messages` (the validation-message category, response key and piece names).

### Inertia: the seeded locale is lowercase

`InertiaSsrProps::share()` hands `initialTranslationsLocale` as `es-es` (1.x: `es-ES`), the form every Langsys SDK identifies a locale by. The JS SDKs accept either, so the hand-off is unchanged; update anything of your own that reads the prop and expects `es-ES`.

### The locale middleware follows Laravel

`DetectLocale` serves the locale your app set when it set one (its own middleware, a user preference). Only when nothing set it does it resolve one from `langsys.locale.sources`, and every candidate must now be a locale your Langsys project serves, narrowed by `supported`. A resolved choice varies the response on `Cookie` or `Accept-Language`.

### Middleware constructors

`DetectLocale`, `FlushPendingRegistrations` and `TranslateResponse` take Laravel's container instead of the SDK `Client`, so an app without a key never builds one. This matters only if you construct them yourself; through the `langsys.*` aliases or the container nothing changes.

### Validation failures carry entries

Every failed validation now carries entries a client SDK translates, beside Laravel's own error body under `langsys_errors` (`langsys.messages.response_key`). Laravel's `message`, `errors`, `$errors` and `@error` are unchanged. See [`docs/server-messages.md`](docs/server-messages.md).
