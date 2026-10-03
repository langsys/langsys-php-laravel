# Server messages and Laravel localization

How this package carries Laravel's validation messages and its `__()` translations into Langsys, and how an application moves from per-locale lang files to Langsys. It implements the framework family (FRM-1 to FRM-8), the server-messages family (MSG-1 to MSG-12) and the legacy-key migration family (MIG-1 to MIG-9) of the SDK behaviour spec; `CONFORMANCE.md` grades each rule. The entry object, markers and fill, the catalog checks, key resolution and conversion, and registration belong to `langsys/langsys-php`. This package supplies Laravel's side. Part 2 is the migration guide, for application developers.

---

# Part 1 — How it works

## 1. What holds

1. **Laravel by the book works, with zero Langsys-specific patterns.** FormRequest and `Validator`; `ValidationException`'s default 422 JSON, `message` plus `errors`; `lang/*` PHP and JSON files; `__()`, `trans()`, `@lang` and `trans_choice()`; `validation.php` messages; `:attribute` substitution and `attributes()`. An application writes none of this package's code to get any of it, and Laravel's defaults stay the defaults.
2. **One chain, one switch.** Every lookup answers from the catalog, then the app's own lang files, then the source — the same order for every key and sentence. `LANGSYS_ENABLED=false` restores Laravel's translator whole.
3. **`:attribute` reconciles with MSG-3** by writing each label into its sentence before translation (§4).

## 2. Once installed

Installing the package is enough (FRM-1). Laravel's own translate function — `__()`, `trans()`, `trans_choice()` and `@lang` — is answered by Langsys, and nothing about how the app calls it changes. `t()` is `__()` by another name.

- **`__()` resolves the catalog, then your lang files, then the source** (FRM-3). A key resolves to its line in your base-language files first (§3.2). With nothing in the catalog, the answer is exactly what Laravel gives without this package. Nothing registers while serving a request: `langsys:sync` does (§6).
- **Validation failures carry entries** built from the rule that failed, with the label written in, beside Laravel's own error body (§3.1, §5).
- **What `__()` returns depends on the response** (FRM-4), read from Laravel's own structure. A page the server renders gets the translation. An Inertia page — Inertia's middleware on the route, or an Inertia visit — gets the source, because its own browser SDK translates it. A notification is always translated, in the recipient's `preferredLocale()`, even when sent while serving an Inertia page, and from a queued job too. `langsys.response_kinds` names the kind for a route group yourself: `auto`, `server` or `client`.

**One switch turns it all off, for debugging:** `LANGSYS_ENABLED=false`. Off, nothing of this package is installed — Laravel's own translator and validator, and a 422 body with nothing of ours.

**`__()` and automatic mode (`TranslateResponse`) do not run together.** The page walk would meet text `__()` already translated, look the translation up as a source phrase, miss, and register it. A project using the page walk turns it on only for routes that do not translate through `__()`.

## 3. How it works


### 3.0 Who translates a validation message

Every entry carries its source `template` and its `params`, so a client SDK looks the template up and renders it itself (MSG-5). What else leaves the server depends on who reads the response next (FRM-4, FRM-5):

- **A JSON response** is read by any client, with an SDK or without one. Each entry's `message` is in the request's language — the locale the app resolved, or Accept-Language negotiated against the locales the project serves — and the response carries `Content-Language`, and `Vary: Accept-Language` when the SDK negotiated. With no match, or no translation, `message` is the source.
- **A redirect**, and the Inertia prop it becomes, carries the source fill: the page it reaches has its own SDK.

Laravel's own text — the message bag, `$errors`, the 422 body's `message` and `errors` — stays as Laravel wrote it, in the source language.

### 3.1 Validation messages — MSG-9, MSG-10, MSG-11

**The hook is a validator resolver.** The service provider installs `Validator::resolver()` with a `Validator` subclass, so every validator Laravel makes — FormRequest, `$request->validate()`, `Validator::make()`, Livewire's — builds entries this way, with no application code.

**The subclass turns each failure into an entry from the rule that failed**, its parameters and the attribute. It never reads text Laravel already rendered (MSG-9):

1. **Label** (MSG-10). Laravel's own label concept, `getDisplayableAttribute()`: FormRequest `attributes()`, the fourth argument of `Validator::make()`, `setAttributeNames()` — in a request's or laravel-data DTO's `withValidator()` too, which the listing runs as Laravel does — and Laravel's derived name when none is declared.
2. **Wording.** A custom message wins, as in Laravel: FormRequest `messages()`, or the inline messages argument. Otherwise it is **Laravel's own line** for the rule, read in the source language from the installed framework's `validation.php`, size variants included (`min.string`, `min.numeric`, `min.array`, `min.file`).
3. **Template** (MSG-3, MSG-11). For each of Laravel's validation rules, a table in this package says what each placeholder in its line stands for:

   | Placeholder | Becomes | Why |
   |---|---|---|
   | `:attribute` | the field's label, written in | translatable, and it governs agreement |
   | `:other` | the other field's label, written in | same |
   | `:value`, `:values` naming fields or options | labels or option names, written in | translatable; a declared value set registers one sentence per value at sync (FRM-7) |
   | `:min` `:max` `:size` `:digits` `:decimal`, a numeric `:value` | `{min}` … markers, sent as numbers | not translatable; plurals are ICU's job (MSG-11) |
   | `:format` `:encoding`, a literal `:date`, literal `:values` lists (extensions, prefixes) | `{format}` … markers | not translatable |

   Laravel's own replacer then fills everything that is left, so the label and any written-in value are exactly what Laravel prints, and filling the template again reproduces Laravel's message byte for byte. A test fails when the installed Laravel ships a rule or placeholder the table does not classify, so an upgrade cannot send an unclassified message.
4. **Code** (MSG-2). Laravel's own name for the rule that failed, passed through: `required`, `min`, `required_if`, the rule as written and as `validation.php` keys it. It does not change with the field's type or the side of a bound. A rule object or closure fails under the class Laravel records it by, and that class is its code.
5. **Entry.** `ServerMessage::make($code, $template, $params, $field)` from the core, with `$field` Laravel's dotted attribute. `$client->emitMessage($entry)` renders it. Registration is sync's (§6); the one exception is a sentence built from a declared value the last sync did not see, sent after the response (FRM-7, MSG-8).

**Rule objects** have no line of Laravel's. One that implements the core's `HasMessageTemplate` states its sentence: `template()` returns it with `:attribute` for the label and a `{name}` marker for each value, filled from the rule's public property of the same name, and the entry is that template with the label written in and those params (FRM-2). Any other rule object, and a closure, has only the text it produced, with the label written in by Laravel, one entry per `$fail()`. **`ValidationException::withMessages()`** carries text and no rule: its text is the template, and the entry carries no code, because Laravel has none for it (MSG-2, MSG-9).

### 3.2 `__()`, `trans()`, `@lang` — keys resolve to source text

Laravel's `translator` is this package's subclass, built lazily so that resolving it never builds a Langsys client. The calling convention is Laravel's; only where the line comes from differs (MIG-8).

1. **The source line.** The application's source-language files — `lang/{locale}.json` and `lang/{locale}/*.php` in `app.fallback_locale` — hold the source. A key resolves to its line there, in the order Laravel reads: the app's JSON, then its groups; the framework's bundled English and any package JSON path only for what the app does not define; a package's `lang/vendor/{namespace}` override ahead of the package's own files. JSON files are declared in Laravel's format, since the core would otherwise read JSON as plain text. Resolution, conversion and interpolation are the core's (`Client::resolve()`); the package hands it the file list.
2. **The phrase is the line, never the key** (MIG-3). Laravel's `:name` becomes `{name}`, and a `|` plural whose forms carry `:count` becomes one ICU plural over `count`, so the phrase is the one every Langsys SDK renders. A line the conversion does not recognise — `:Name`, a range with no exact equivalent — is registered as written, with a warning.
3. **The category is the group:** `messages.welcome` registers under `messages`, `courier::notices.sent` under `notices`.
4. **Translation.** The phrase is looked up in the catalog in the request locale. On a miss, the app's own lang-file line for that locale answers, else the source line, filled (FRM-3). Nothing registers: `langsys:sync` does (§6).
5. **A sentence passed as its own key** is literal source text, converted as the Laravel call that received it reads it (MIG-2). `__('Hello :name', ['name' => $n])` registers `Hello {name}`, the phrase every Langsys SDK looks up. A `:word` the call does not pass (`__('Note:done')`) and a `|` through `__()` print as written in Laravel, so they register as written. `trans_choice(':count item|:count items', $n)` reads the `|` as a plural and registers the ICU plural. `:Name` and `:NAME` register as written, with a warning.
6. **A package key no file holds** returns the key and registers nothing. **An application key no file holds** is literal text, logged at debug, so a mistyped or deleted key is visible.
7. **Replacements** travel as params and fill the phrase's `{name}` markers after translation. They carry values — a name, a count — never translatable text.
8. **Validation lines** never go through this path. Laravel's translator keeps answering `validation.*`, which is where §3.1 reads its lines.

## 4. `:attribute` under MSG-3

**Laravel translates `The :attribute field is required.`, then substitutes a separately translated label.** Whatever the label is, the sentence around it was inflected before the label was known — `Contraseña es obligatorio`. MSG-3 exists to remove exactly this.

**This package moves the substitution before translation, into the source language.** The label is written into the English sentence, and the sentence is the phrase: `The password field is required.` and `The name field is required.` are two catalog entries, each translated whole.

- **`attributes()` keeps its meaning — the field's name as a person reads it — and its values stay in the source language.** A label is never translated on its own; it is translated as part of each sentence it appears in.
- **`:attribute` in custom messages works the same way:** `messages()` stays by the book, and the label is written into each sentence.
- **Translation volume grows from one phrase per rule to one per field per rule.** The listing command lists and registers all of them ahead of time (§6).
- **Markers are only for values** — numbers, dates, formats. `The password field must be at least {min} characters.` is one phrase for every minimum; a language that inflects around the number does it in ICU (MSG-11).
- **One case stays open:** a non-translatable proper noun that governs agreement, such as a person's name. Its fix waits on gender-select (#827).

**Laravel's wording is kept exactly, "field" included**, so a Spanish sentence anchors its agreement on *campo* rather than on the label. That is correct, if wooden, and it changes nothing about enumeration: the label is written into every sentence and one phrase is registered per field, for every rule. Keeping `:attribute` as a placeholder wherever the wording happened to make agreement safe would behave differently from every other rule, break the moment the wording changed, and put a translatable value in a marker, which MSG-3 forbids.

## 5. Where entries go — MSG-1, MSG-12

An entry is the core's: `{field?, code?, message, template, params?}`. The error body around it stays Laravel's own.

- **JSON 422.** `{message, errors}` keeps its shape and its source-language text. The entries go beside them, each `message` in the request's language (§3.0), under `langsys.messages.response_key`, default `langsys_errors`, and `langsys.messages.pieces` renames an entry's pieces for a client that expects other names. A response middleware, appended to the `web` and `api` groups, reads the `ValidationException` Laravel's pipeline attaches to the response and takes the entries from its validator.
- **Redirects.** A form that fails and redirects flashes its entries to the session under the same key, beside Laravel's own `errors` bag.
- **An API with its own error envelope** sets `langsys.messages.response_key` empty. Nothing is attached or flashed; its exception handler reads the entries from the `ValidationException`'s validator (`serverMessages()`) and puts each `message` in the request's language with `Client::translateMessage()`.
- **Inertia (MSG-12).** When `inertiajs/inertia-laravel` is installed, the middleware shares the flashed entries as a page prop under the same key on the way in, before the page renders, so the destination page's JS SDK renders them (MSG-5). Inertia's conditional props are no help here — `lazy` and `optional` withhold a prop from exactly the full page load this has to reach — so the sharing itself is conditional, and a page that follows no failure carries no prop of ours. Inertia's own `errors` prop is untouched. Inertia is a development dependency of this package only.
- **Category (MSG-6).** `langsys.messages.category`, default `Errors`, is the core's `messages_category`. Rendering, sync and the listing command all use it.

System messages — `abort()`, authorization and HTTP exceptions — carry no entries.

## 6. Sync — FRM-2, MSG-7

`php artisan langsys:sync [--dry-run] [--strict] [--watch] [-v]` is the one way anything is registered. It reads every literal `__()`, `trans()`, `trans_choice()`, `@lang` and `t()` in the app's PHP and Blade — Blade compiled by Laravel's own compiler, each hit's line found again in the view — and every line of the base-language files, Laravel's own bundled English included. For each phrase:

- **in the catalog** — nothing;
- **in the lang files, not the catalog** — registered with the translations the other locales' files already have, in one call, so work already done is kept (MIG-9);
- **in neither** — registered alone.

A call whose argument is not a literal is reported with its file and line; one that only builds its key inside a literal group — `__("messages.$key")` — is covered by that group, whose every line registers, and fails nothing. A declared value set (FRM-7) registers one sentence per value, the word written in. The validation messages the app can send register beside them (MSG-7), from every FormRequest and laravel-data request DTO a route action takes: each rule of each field with the field's label written in, and each rule object's template, once per field that uses it. Laravel's own rule objects (`Rules\Enum`, which laravel-data infers for an enum property) are listed from Laravel's line, which no app can give a template. A field that cannot be listed is reported and the listing carries on. A rule object of the app's without `HasMessageTemplate` is listed from its filled message and fails `--strict`, naming the interface: a value filled into that message would become part of the phrase. A line holding `:attribute`, `:other` or `:values` never registers on its own — it is a phrase no request looks up — and the command reports it as registered through the validation listing.

`--dry-run` lists without registering. `--strict` exits 1 on any call it could not register ahead of time, for CI. `--watch` syncs again whenever a scanned file or lang file changes, for development. `langsys.sync_paths` replaces the directories it reads (app/, routes/, resources/views/). `php artisan langsys:messages` lists the validation messages alone, and reports what cannot be listed. A field with no declared label is named as advice, with the name Laravel prints for it (MSG-10): advice is printed apart and never fails the build, `--strict` included.

## 7. Known limits

- **Option labels.** Laravel keeps display names for option values in `validation.values` lang lines. Without per-locale files they are declared in code: `setValueNames()` in a FormRequest's `withValidator()` (Part 2, step 4).

---

# Part 2 — Migrating from lang files to Langsys

This moves an application from per-locale `lang/*` files and `validation.php` to Langsys, keeping only its source-language files. Every step is reversible until the last one. **Setting `LANGSYS_ENABLED=false` restores Laravel's behaviour at any point**, provided the lang files are still in git.

**Before you start:** the package is installed and configured (`LANGSYS_API_KEY`, `LANGSYS_PROJECT_ID`). Use a **write** key in development and in the CI job that registers phrases, and a **read-only** key in production.

### Step 1 — Take stock

```bash
php artisan langsys:messages -v
```

The command lists every message your validation can produce, and every problem that would stop one being translated ahead of time. It changes nothing. Keep the output: the steps below clear its problem lines.

### Step 2 — Move field labels into your requests

Validation sentences are built with the label written in, so there is no `validation.attributes`. Each label moves to the request that validates the field, **in your source language**:

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

A rule object of your own states its sentence the same way, through `Langsys\SDK\Messages\HasMessageTemplate`, with each value a `{name}` marker named after a public property:

```php
class MaxWords implements ValidationRule, HasMessageTemplate
{
    public function __construct(public int $max) {}

    public function template(): string
    {
        return 'The :attribute may not be more than {max} words.';
    }

    // validate() as before
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

Your keys keep working. `__('messages.welcome', ['name' => $user->name])` resolves to `Welcome back, :name` in `lang/en/messages.php`, and that sentence — as `Welcome back, {name}` — is what Langsys registers and translates. Keep `lang/en.json` and `lang/en/*.php` (your `app.fallback_locale`), and `lang/vendor/{package}/en` if you override a package's English. The other locales' files answer what the catalog lacks, until step 11. Laravel's own groups — `auth`, `pagination`, `passwords` — need nothing: their English ships with the framework.

Over time, you can write the sentence at the call site instead, `__('Welcome back, :name', ...)`, and delete the line. The phrase stays the same, so no translation is lost.

### Step 6 — Keep replacements for values only

A `:name` replacement is filled *after* translation, so it must never hold translatable text:

```php
__('Welcome back, :name', ['name' => $user->name])   // fine: a person's name
__('Your :plan plan renews soon', ['plan' => 'Pro']) // fine: a product name you don't translate
__('The :thing was deleted', ['thing' => 'invoice']) // not fine: "invoice" never reaches a translator
```

Write each translatable variant as its own sentence instead — `The invoice was deleted.`, `The project was deleted.` When the value comes from a finite set, such as a status, declare the set — a backed enum marked `#[TranslatesAs]`, or a class implementing `TranslatableValues` — and `langsys:sync` registers one sentence per value, the word written in (FRM-7).

### Step 7 — Check your plurals and capitalised placeholders

`trans_choice('messages.apples', $n)` with `:count apple|:count apples` becomes `{count, plural, one {# apple} other {# apples}}`, and `{0}` / `[1,*]` ranges map to exact counts and categories where they cover every number. A form with no ICU equivalent — `:Name` or `:NAME`, a range that skips a number — is registered as written and warned about in your log. Rewrite those lines: `:Name` as `{name}` with the value capitalised by your code, a plural as ICU.

### Step 8 — Register ahead of time

```bash
php artisan langsys:sync
```

Run it in CI with a write key. It registers every phrase and validation sentence it can list, so each is translated before a user sees it, and it is safe to run repeatedly. Add `--strict` to fail the build on any problem left from step 1.

### Step 9 — Carry over existing translations

Every line in your lang files brings its translations with it: `langsys:sync` registers the source line with the translations your other locales' files hold, as human translations, so they are not machine-translated again. Validation messages cannot: `The card number field is required.` is a new sentence with its label written in, so it is translated fresh — by machine translation, then reviewed in the Translation Manager. This is the one place migration costs translation work. It is the price of correct agreement.

### Step 10 — Check

The package has been answering since it was installed. Run your test suite. Then check one failing form in the browser, and one failing JSON request:

- The 422 body still has `message` and `errors` in your source language, plus `langsys_errors` entries whose `message` is in the request's language.
- A redirecting form still shows `$errors` in Blade.
- An Inertia page receives `langsys_errors` as a prop.

### Step 11 — Delete the other locales' files

Once the catalog holds their translations (step 9), the other locales' files are only a fallback. Delete them when you no longer want it, keeping your source language's, which your keys still resolve through (step 5). `validation.php` can go too: validation sentences are built from the rule that failed. **To roll back:** restore it from git and set `LANGSYS_ENABLED=false`.

### What you don't have to change

- FormRequests, `$request->validate()`, `Validator::make()`, Livewire validation.
- Blade's `$errors` and `@error`.
- Your 422 JSON handling.
- `__()`, `trans()`, `@lang` and `trans_choice()` calls, keys included.
- `t()`, which is `__()` by another name.

Your application keeps writing Laravel. Only where the words come from changes.
