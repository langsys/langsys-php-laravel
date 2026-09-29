# Server messages and Laravel localization

How this package carries Laravel's validation messages and its `__()` translations into Langsys, and how an application moves from per-locale lang files to Langsys. It implements the server-messages family (MSG-1 to MSG-12) and the legacy-key migration family (MIG-1 to MIG-8) of the SDK behaviour spec; `CONFORMANCE.md` grades each rule. The entry object, markers and fill, the catalog checks, key resolution and conversion, and registration belong to `langsys/langsys-php`. This package supplies Laravel's side. Part 2 is the migration guide, for application developers.

---

# Part 1 — How it works

## 1. What holds

1. **Laravel by the book works, with zero Langsys-specific patterns.** FormRequest and `Validator`; `ValidationException`'s default 422 JSON, `message` plus `errors`; `lang/*` PHP and JSON files; `__()`, `trans()`, `@lang` and `trans_choice()`; `validation.php` messages; `:attribute` substitution and `attributes()`. An application writes none of this package's code to get any of it, and Laravel's defaults stay the defaults.
2. **Keep or migrate, never a silent hybrid.** An application's localization either keeps working exactly as it is, with Laravel's translator authoritative, or moves to Langsys, keeping only its source-language files. No key changes hands quietly.
3. **`:attribute` reconciles with MSG-3** by writing each label into its sentence before translation (§4).

## 2. Modes

One setting chooses the mode: `langsys.localization`, from `LANGSYS_LOCALIZATION`. **The default is `keep`.** It decides which of Laravel's own services answers Laravel's localization calls, and changes nothing about what the core does once asked.

### `keep` — Laravel stays authoritative

The package touches nothing about localization or validation. It installs no validator resolver, no translator and no response middleware. Laravel's translator answers every `__()`, and validation messages come from the lang files or Laravel's defaults, exactly as before. **No entries are emitted:** a 422 body is byte-identical to the same application without this package, and a test pins it. `t()` and `@t` keep working as the package's tagged mode.

Keep mode emits nothing because an entry registers a template for translation. Emitted beside an application's own lang files, a frontend rendering the entries would show Langsys's translation of the source sentence where the application shows its own — the silent hybrid requirement 2 rules out.

### `migrate` — Langsys answers, from your source-language files

- `__()`, `trans()`, `@lang` and `trans_choice()` resolve keys to their line in your source-language files and translate that line in Langsys (§3.2).
- Validation messages become entries built from the rule that failed, with the label written in, registered for translation; Laravel's own rendering is left alone (§3.1).
- Entries are attached to responses in Laravel's own error body, carried across redirects, and shared with Inertia (§5).
- Phrases and templates the catalog lacks are registered after the response, under a write key only, on the existing flush path.

**Migrate mode and automatic mode (`TranslateResponse`) do not run together.** The page walk would meet text `__()` already translated, look the translation up as a source phrase, miss, and register it — the hazard documented for `@t`. A project picks one.

A third mode, `fill`, where Laravel's files answer first and Langsys covers only what they miss, is not available yet; `ROADMAP.md` records its design.

## 3. Migrate mode

### 3.0 Who translates a validation message

Every entry carries its source `template` and its `params`, so a client SDK looks the template up and renders it itself (MSG-5). What else leaves the server depends on who reads the response next (FRM-4, FRM-5):

- **A JSON response** is read by any client, with an SDK or without one. Each entry's `message` is in the request's language — the locale the app resolved, or Accept-Language negotiated against the locales the project serves — and the response carries `Content-Language`, and `Vary: Accept-Language` when the SDK negotiated. With no match, or no translation, `message` is the source.
- **A redirect**, and the Inertia prop it becomes, carries the source fill: the page it reaches has its own SDK.

Laravel's own text — the message bag, `$errors`, the 422 body's `message` and `errors` — stays as Laravel wrote it, in the source language.

### 3.1 Validation messages — MSG-9, MSG-10, MSG-11

**The hook is a validator resolver.** In migrate mode the service provider installs `Validator::resolver()` with a `Validator` subclass, so every validator Laravel makes — FormRequest, `$request->validate()`, `Validator::make()`, Livewire's — builds entries this way, with no application code.

**The subclass turns each failure into an entry from the rule that failed**, its parameters and the attribute. It never reads text Laravel already rendered (MSG-9):

1. **Label** (MSG-10). Laravel's own label concept, `getDisplayableAttribute()`: FormRequest `attributes()`, the fourth argument of `Validator::make()`, `setAttributeNames()`, and Laravel's derived name when none is declared.
2. **Wording.** A custom message wins, as in Laravel: FormRequest `messages()`, or the inline messages argument. Otherwise it is **Laravel's own line** for the rule, read in the source language from the installed framework's `validation.php`, size variants included (`min.string`, `min.numeric`, `min.array`, `min.file`).
3. **Template** (MSG-3, MSG-11). For each of Laravel's validation rules, a table in this package says what each placeholder in its line stands for:

   | Placeholder | Becomes | Why |
   |---|---|---|
   | `:attribute` | the field's label, written in | translatable, and it governs agreement |
   | `:other` | the other field's label, written in | same |
   | `:value`, `:values` naming fields or options | labels or option names, written in | translatable; a new option registers when first emitted (MSG-8) |
   | `:min` `:max` `:size` `:digits` `:decimal`, a numeric `:value` | `{min}` … markers, sent as numbers | not translatable; plurals are ICU's job (MSG-11) |
   | `:format` `:encoding`, a literal `:date`, literal `:values` lists (extensions, prefixes) | `{format}` … markers | not translatable |

   Laravel's own replacer then fills everything that is left, so the label and any written-in value are exactly what Laravel prints, and filling the template again reproduces Laravel's message byte for byte. A test fails when the installed Laravel ships a rule or placeholder the table does not classify, so an upgrade cannot send an unclassified message.
4. **Code** (MSG-2). Laravel's own name for the rule that failed, passed through: `required`, `min`, `required_if`, the rule as written and as `validation.php` keys it. It does not change with the field's type or the side of a bound. A rule object or closure fails under the class Laravel records it by, and that class is its code.
5. **Entry.** `ServerMessage::make($code, $template, $params, $field)` from the core, with `$field` Laravel's dotted attribute. `$client->emitMessage($entry)` registers the template when the catalog lacks it, after the response, so the request is never blocked (MSG-8).

**Rule objects and closures** have no line of Laravel's, so their template is the text they produced, with the label written in by Laravel. **`ValidationException::withMessages()`** carries text and no rule: its text is the template, and the entry carries no code, because Laravel has none for it (MSG-2, MSG-9).

### 3.2 `__()`, `trans()`, `@lang` — keys resolve to source text

In migrate mode Laravel's `translator` is this package's subclass, built lazily so that resolving it never builds a Langsys client. The calling convention is Laravel's; only where the line comes from differs (MIG-8).

1. **The source line.** The application keeps its source-language files — `lang/{locale}.json` and `lang/{locale}/*.php` in `app.fallback_locale` — and deletes the rest. A key resolves to its line there, in the order Laravel reads: the app's JSON, then its groups; the framework's bundled English and any package JSON path only for what the app does not define; a package's `lang/vendor/{namespace}` override ahead of the package's own files. JSON files are declared in Laravel's format, since the core would otherwise read JSON as plain text. Resolution, conversion and interpolation are the core's (`Client::translate()` in its migration mode); the package hands it the file list.
2. **The phrase is the line, never the key** (MIG-3). Laravel's `:name` becomes `{name}`, and a `|` plural whose forms carry `:count` becomes one ICU plural over `count`, so the phrase is the one every Langsys SDK renders. A line the conversion does not recognise — `:Name`, a range with no exact equivalent — is registered as written, with a warning.
3. **The category is the group:** `messages.welcome` registers under `messages`, `courier::notices.sent` under `notices`.
4. **Translation.** The phrase is looked up in the catalog in the request locale. On a miss, the source line is returned, filled, and queued for registration.
5. **A sentence passed as its own key** is literal source text, converted as the Laravel call that received it reads it (MIG-2). `__('Hello :name', ['name' => $n])` registers `Hello {name}`, the same phrase and id as `t('Hello {name}')`. A `:word` the call does not pass (`__('Note:done')`) and a `|` through `__()` print as written in Laravel, so they register as written. `trans_choice(':count item|:count items', $n)` reads the `|` as a plural and registers the ICU plural. `:Name` and `:NAME` register as written, with a warning.
6. **A package key no file holds** returns the key and registers nothing. **An application key no file holds** is literal text, logged at debug, so a mistyped or deleted key is visible.
7. **Replacements** travel as params and fill the phrase's `{name}` markers after translation. They carry values — a name, a count — never translatable text.
8. **Validation lines** never go through this path. Laravel's translator keeps answering `validation.*`, which is where §3.1 reads its lines.

## 4. `:attribute` under MSG-3

**Laravel translates `The :attribute field is required.`, then substitutes a separately translated label.** Whatever the label is, the sentence around it was inflected before the label was known — `Contraseña es obligatorio`. MSG-3 exists to remove exactly this.

**Migrate mode moves the substitution before translation, into the source language.** The label is written into the English sentence, and the sentence is the phrase: `The password field is required.` and `The name field is required.` are two catalog entries, each translated whole.

- **`attributes()` keeps its meaning — the field's name as a person reads it — and its values stay in the source language.** A label is never translated on its own; it is translated as part of each sentence it appears in.
- **`:attribute` in custom messages works the same way:** `messages()` stays by the book, and the label is written into each sentence.
- **Translation volume grows from one phrase per rule to one per field per rule.** The listing command lists and registers all of them ahead of time (§6).
- **Markers are only for values** — numbers, dates, formats. `The password field must be at least {min} characters.` is one phrase for every minimum; a language that inflects around the number does it in ICU (MSG-11).
- **One case stays open:** a non-translatable proper noun that governs agreement, such as a person's name. Its fix waits on gender-select (#827).

**Laravel's wording is kept exactly, "field" included**, so a Spanish sentence anchors its agreement on *campo* rather than on the label. That is correct, if wooden, and it changes nothing about enumeration: the label is written into every sentence and one phrase is registered per field, for every rule. Keeping `:attribute` as a placeholder wherever the wording happened to make agreement safe would behave differently from every other rule, break the moment the wording changed, and put a translatable value in a marker, which MSG-3 forbids.

Keep mode leaves `:attribute` exactly as Laravel has it, agreement included.

## 5. Where entries go — MSG-1, MSG-12

An entry is the core's: `{field?, code?, message, template, params?}`. The error body around it stays Laravel's own.

- **JSON 422.** `{message, errors}` keeps its shape and its source-language text. The entries go beside them, each `message` in the request's language (§3.0), under `langsys.messages.response_key`, default `langsys_errors`, and `langsys.messages.pieces` renames an entry's pieces for a client that expects other names. A response middleware, appended to the `web` and `api` groups in migrate mode only, reads the `ValidationException` Laravel's pipeline attaches to the response and takes the entries from its validator.
- **Redirects.** A form that fails and redirects flashes its entries to the session under the same key, beside Laravel's own `errors` bag.
- **Inertia (MSG-12).** When `inertiajs/inertia-laravel` is installed, the middleware shares the flashed entries as a page prop under the same key on the way in, before the page renders, so the destination page's JS SDK renders them (MSG-5). Inertia's conditional props are no help here — `lazy` and `optional` withhold a prop from exactly the full page load this has to reach — so the sharing itself is conditional, and a page that follows no failure carries no prop of ours. Inertia's own `errors` prop is untouched. Inertia is a development dependency of this package only.
- **Category (MSG-6).** `langsys.messages.category`, default `Errors`, is the core's `messages_category`. Rendering, runtime registration and the listing command all use it.

System messages — `abort()`, authorization and HTTP exceptions — carry no entries.

## 6. Listing command — MSG-7

`php artisan langsys:messages [--register] [--strict] [-v]` lists every validation message the application can send, reports what it cannot list, and with `--register` registers the templates the catalog lacks under the configured category. Listing, registration and the exit codes are the core's `MessageCatalogCommand`; the package supplies Laravel's sources and prints through Artisan. **It reports by default and exits 0**, because a message that cannot be listed still registers the first time it is sent (MSG-8). **`--strict` exits 1 on any problem**, for a build that allows no untranslated message.

| Source | Lists | Reports |
|---|---|---|
| FormRequests that routes' controller actions take | each rule of each field, built by the same code as the entry a failing request sends, with the field's label and the app's own `messages()` | a field with no declared label, with the name Laravel prints instead (MSG-10); `rules()` that cannot be built without a live request; a wildcard field with no label; a rule object or closure; a rule Laravel ships no line for; a placeholder left unfilled, `:attribute` included (the core's MSG-11 check) |
| Migrate mode's lang files (the core's `LegacyKeysSource`) | nothing: they are phrases, not messages | a line the conversion does not recognise; a key defined in two of the app's files |

`--register` is idempotent. **Not listed:** rules written inline with `$request->validate([...])` or `Validator::make()` in a controller, and rule objects such as `Password`. They register the first time they are sent (MSG-8); a FormRequest with string rules is what makes a message ready on day one.

## 7. Known limits

- **Option labels.** Laravel keeps display names for option values in `validation.values` lang lines. Without per-locale files they are declared in code: `setValueNames()` in a FormRequest's `withValidator()` (Part 2, step 4).
- **Existing translations are not imported.** Part 2, step 9, says what carries over; the core's import waits on the backend.

---

# Part 2 — Migrating from lang files to Langsys

This moves an application from per-locale `lang/*` files and `validation.php` to Langsys, keeping only its source-language files. Every step is reversible until the last one. **Setting `LANGSYS_LOCALIZATION=keep` restores Laravel's behaviour at any point**, provided the lang files are still in git.

**Before you start:** the package is installed and configured (`LANGSYS_API_KEY`, `LANGSYS_PROJECT_ID`). Use a **write** key in development and in the CI job that registers phrases, and a **read-only** key in production.

### Step 1 — Take stock

```bash
php artisan langsys:messages -v
```

The command lists every message your validation can produce, and every problem that would stop one being translated ahead of time. It works in either mode and changes nothing. Keep the output: the steps below clear its problem lines.

### Step 2 — Move field labels into your requests

In migrate mode there is no `validation.attributes`. Each label moves to the request that validates the field, **in your source language**:

```php
// Before: lang/en/validation.php
'attributes' => ['cc_number' => 'card number'],

// After: app/Http/Requests/StorePaymentRequest.php
public function attributes(): array
{
    return ['cc_number' => 'card number'];
}
```

Labels shared by many requests can live in a base request class or a trait. **Don't translate a label on its own.** It is written into each sentence, and the sentence is translated whole — that is what makes `La contraseña es obligatoria` agree.

### Step 3 — Move custom messages into your requests

`validation.custom` goes the same way, into `messages()`, in your source language. Keep `:attribute` — it is filled with the label before translation:

```php
public function messages(): array
{
    return ['email.unique' => 'An account already uses this :attribute.'];
}
```

### Step 4 — Declare option names

If you relied on `validation.values` to show `:value` or `:values` as readable names, declare them in the request:

```php
public function withValidator($validator): void
{
    $validator->setValueNames(['type' => ['business' => 'business account']]);
}
```

### Step 5 — Keep only your source-language files

Your keys keep working. `__('messages.welcome', ['name' => $user->name])` resolves to `Welcome back, :name` in `lang/en/messages.php`, and that sentence — as `Welcome back, {name}` — is what Langsys registers and translates. Keep `lang/en.json` and `lang/en/*.php` (your `app.fallback_locale`), and `lang/vendor/{package}/en` if you override a package's English. The other locales' files are the ones step 11 deletes. Laravel's own groups — `auth`, `pagination`, `passwords` — need nothing: their English ships with the framework.

Over time, you can write the sentence at the call site instead, `__('Welcome back, :name', ...)`, and delete the line. The phrase stays the same, so no translation is lost.

### Step 6 — Keep replacements for values only

A `:name` replacement is filled *after* translation, so it must never hold translatable text:

```php
__('Welcome back, :name', ['name' => $user->name])   // fine: a person's name
__('Your :plan plan renews soon', ['plan' => 'Pro']) // fine: a product name you don't translate
__('The :thing was deleted', ['thing' => 'invoice']) // not fine: "invoice" never reaches a translator
```

Write each translatable variant as its own sentence instead — `The invoice was deleted.`, `The project was deleted.` A variant your code discovers at runtime registers the first time it is shown.

### Step 7 — Check your plurals and capitalised placeholders

`trans_choice('messages.apples', $n)` with `:count apple|:count apples` becomes `{count, plural, one {# apple} other {# apples}}`, and `{0}` / `[1,*]` ranges map to exact counts and categories where they cover every number. A form with no ICU equivalent — `:Name` or `:NAME`, a range that skips a number — is registered as written and warned about in your log. Rewrite those lines: `:Name` as `{name}` with the value capitalised by your code, a plural as ICU.

### Step 8 — Register ahead of time

```bash
php artisan langsys:messages --register
```

Run it in CI with a write key. It registers every validation sentence it can list, so each is translated before a user sees it, and it is safe to run repeatedly. Add `--strict` to fail the build on any problem left from step 1.

### Step 9 — Carry over existing translations

Every line in your lang files can bring its translations with it: the phrase is the source line, and your other locales' files hold its translation. This package does not import them yet, so enter or upload them in the Translation Manager, or let machine translation fill them in and review. Validation messages cannot: `The card number field is required.` is a new sentence with its label written in, so it is translated fresh — by machine translation, then reviewed in the Translation Manager. This is the one place migration costs translation work. It is the price of correct agreement.

### Step 10 — Switch, and check

```dotenv
LANGSYS_LOCALIZATION=migrate
```

Run your test suite. Then check one failing form in the browser, and one failing JSON request:

- The 422 body still has `message` and `errors`, in your source language, plus `langsys_errors` entries for a client to translate.
- A redirecting form still shows `$errors` in Blade.
- An Inertia page receives `langsys_errors` as a prop.

### Step 11 — Delete the other locales' files

Once the application behaves as it should in `migrate`, delete every locale's lang files except your source language's, which your keys still resolve through (step 5). `validation.php` can go too: migrate mode builds validation sentences from the rule that failed. **To roll back:** restore it from git and set `LANGSYS_LOCALIZATION=keep`.

### What you don't have to change

- FormRequests, `$request->validate()`, `Validator::make()`, Livewire validation.
- Blade's `$errors` and `@error`.
- Your 422 JSON handling.
- `__()`, `trans()`, `@lang` and `trans_choice()` calls, keys included.
- `t()` and `@t`.

Your application keeps writing Laravel. Only where the words come from changes.
