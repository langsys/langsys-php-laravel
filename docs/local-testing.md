# Testing this branch locally

How to try every feature of this branch in a Laravel app on your machine, before the release. Each section says what to set up, what to run, and what you should see.

## 1. Install from your checkouts

Keep `langsys-php-laravel` and `langsys-php-sdk` side by side, and point a Laravel app at both through Composer path repositories. In the app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../langsys-php-sdk", "options": { "symlink": true, "versions": { "langsys/langsys-php": "1.3.1" } } },
    { "type": "path", "url": "../langsys-php-laravel", "options": { "symlink": true } }
],
"require": {
    "langsys/langsys-php-laravel": "@dev"
}
```

The `versions` entry lets the core's feature branch satisfy this package's `^1.3`. Then:

```bash
composer update langsys/langsys-php-laravel langsys/langsys-php
php artisan vendor:publish --tag=langsys-config
```

In `.env`, a project and a key, and the local API if you run one:

```dotenv
LANGSYS_PROJECT_ID=your-project-id
LANGSYS_API_KEY=a-write-key          # sync registers with it; serve with a read-only key
LANGSYS_API_URL=http://langsys2.test/api
```

**Check:** with the key commented out, every page still renders exactly as plain Laravel, and `storage/logs` has one debug line naming the missing key.

## 2. `langsys:sync`

```bash
php artisan langsys:sync --dry-run -v     # what would register, per phrase; registers nothing
php artisan langsys:sync --dry-run --strict   # exits 1 on a call it cannot read as a literal
php artisan langsys:sync                  # registers, with your lang files' translations
php artisan langsys:sync --watch          # syncs again whenever a PHP, Blade or lang file changes
```

**Check:**
- Add `{{ __('Checkout now') }}` to a view and run the dry run: it lists `Checkout now` as new.
- `__($variable)` is reported with its file and line, and fails `--strict`; `__("validation.$key")` is listed as covered by its group and passes.
- A line in `lang/es.json` that translates an English line registers with that translation, and appears translated in the Translation Manager without machine translation.
- A second real run registers nothing new.
- With no key, `--dry-run` still runs, says nothing is compared with the catalog, and `--strict` still fails on a non-literal call.

## 3. What each response gets

**Check:**
- A Blade page served in Spanish (`?locale=es-ES`) shows the catalog's translations, and its `<html>` carries `data-ls-resolved="es-es"`.
- The same `__()` on an Inertia route returns the English source: the page's JS SDK translates it.
- `config('langsys.response_kinds')` set to `['web' => 'client']` makes the Blade page return the source; `'server'` makes the Inertia route translate.
- A notification to a user whose `preferredLocale()` is `es-ES` arrives in Spanish, sent from an Inertia route or a queued job.

## 4. A JSON error in the reader's language

Post an invalid form to an API route:

```bash
curl -s -i -X POST http://your-app.test/api/orders \
  -H 'Accept: application/json' -H 'Accept-Language: es;q=0.9, en;q=0.8' | less
```

**Check:**
- `Content-Language: es-es` and `Vary: Accept-Language` on the response.
- Laravel's own `message` and `errors` in English; beside them `langsys_errors`, each entry with `message` in Spanish and `template`, `params` and `code`.
- `Accept-Language: ja` gives `message` in English and `Content-Language` the project's base locale.
- A `403` or `abort(404, __('…'))` JSON response carries `Content-Language` too.
- With `LANGSYS_MESSAGES_RESPONSE_KEY=` (empty) nothing is added to the body.

## 5. Values Blade prints

In a view: `<p>Hello {{ $user->name }}, welcome back</p>`.

**Check:**
- View the page source: `<p>Hello <!--ls:name-->Ana<!--/ls-->, welcome back</p>`.
- An attribute (`<input value="{{ $user->name }}">`), `<title>`, `<script>`, `{!! !!}` and `{{ __('…') }}` carry no marker.
- On a route with `langsys.translate-page`, two users' visits register one phrase, `Hello {name}, welcome back`, in the Translation Manager, and neither name.

After changing Blade output while testing, run `php artisan view:clear`: compiled views keep the markers they were compiled with.

## 6. Mail sent from an Inertia page

With `MAIL_MAILER=log`, send a Mailable from a controller on an Inertia route, with the app locale Spanish and the subject and body written with `__()`.

**Check:** the log shows the subject and body in Spanish, while the Inertia page's own `__()` returns the English source. A `Mail::send('emails.x', …)` of a plain view, and `(new YourMailable)->render()`, are translated the same way.

## 7. The app's own messages

Add a class in `app/` implementing `Langsys\SDK\Messages\HasAppMessageTemplate`:

```php
final class QuotaExceeded implements HasAppMessageTemplate
{
    public function __construct(public int $limit) {}

    public function template(): string { return 'You have used all {limit} requests this month.'; }

    public function code(): string { return 'quota_exceeded'; }
}
```

**Check:**
- `php artisan langsys:messages -v` lists it under the messages category (`Errors` by default) with its code; a backed enum implementing the contract lists each case.
- `php artisan langsys:sync` registers it under `Errors`, and the `__()` inside a `template()` never registers again as an uncategorised phrase.
- A field with no declared label is printed under `Advice:` and does not fail `--strict`.

## 8. Laravel 10, 11 and 12

The suite passes on all three. To run it against one, in a copy of this package:

```bash
composer require --dev -W "laravel/framework:10.*" "orchestra/testbench:^8.22"
vendor/bin/phpunit
```
