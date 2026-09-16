# Server messages and Laravel localization

**Status: plan, nothing implemented yet.** It covers the server-messages family, MSG-1 to MSG-12, of the SDK behaviour spec 8.1.0 (`langsys2` `70320628`, `docs/sdk-spec.mdx` blob `f8ff6e1e`). That text is unreviewed. The core half — the entry object, markers and fill, the code vocabulary, the catalog and registration — belongs to `langsys/langsys-php`, and its API is being agreed with the PHP lane; names marked *pending* may still change. Part 2 is the migration guide, written for application developers.

---

# Part 1 — Plan

## 1. What has to hold

These are the operator's requirements, and every decision below is measured against them.

1. **Laravel by the book works perfectly, with zero Langsys-specific patterns.** FormRequest and `Validator`; `ValidationException`'s default 422 JSON, `message` plus `errors`; `lang/*` PHP and JSON files; `__()`, `trans()` and `@lang`; `validation.php` messages; `:attribute` substitution and the `attributes` array. An application writes none of our code to get any of it. Defaults are Laravel's defaults.
2. **Keep or migrate, never a hybrid.** An application's existing localization either keeps working exactly as it is, with Laravel's translator authoritative, or moves to the Langsys zero-file model: no lang files, phrases discovered at runtime and translated in Langsys. There is no third path where some keys quietly change hands.
3. **A migration guide** from lang files and `validation.php` to zero-file (Part 2).
4. **An account of how `:attribute` reconciles with MSG-3**, which writes labels into whole sentences and keeps markers for non-translatable values only (§4).

## 2. Two modes, one switch

A single setting chooses the mode: `langsys.localization`, set from `LANGSYS_LOCALIZATION`: `keep`, `fill` or `migrate`. **The default is `keep`.**

### `keep` — Laravel stays authoritative

The package touches nothing about localization or validation. It installs no validator resolver, no translator and no response middleware. Laravel's translator answers every `__()`, and validation messages come from the lang files, or Laravel's defaults, exactly as before. **No entries are emitted:** a 422 body is byte-identical to the same application without this package. That is a test, not a promise. `t()` and `@t` keep working as the package's existing tagged mode.

*Why keep mode emits nothing:* an entry carries a template worded by the Langsys wording table. If keep mode emitted entries, a frontend that rendered them would show wording different from the application's own lang files — the silent hybrid requirement 2 rules out.

### `fill` — Laravel first, Langsys for the gaps

Laravel's lang files answer wherever they have a line for the requested locale. Where they do not — a JSON key with no entry in that locale, or a group key that only the fallback locale has — Langsys is asked for the sentence: it renders the translation when it has one, and otherwise shows the source text and registers it. **Validation messages are the exception**: they are never translated on the server, in any mode, for the reason in §3.0.

This is a hybrid, deliberately, and an explicit one. **It only acts where Laravel had no translation to give**, so no key changes hands silently: a line an application has translated keeps coming from that line.

Two consequences shape the implementation:

- **Laravel's own hook is not enough.** `handleMissingKeysUsing()` fires only when a key is missing from *every* locale. When `es` lacks a line that `en` has, Laravel quietly serves the English one and the hook never runs, so fill mode needs the translator subclass of §3.2 to see a gap in the requested locale.
- **Validation lines cannot be filled as plain lines.** Laravel translates `The :attribute field is required.` first and substitutes the label after, which is the frozen-agreement bug MSG-3 exists to remove. So when a validation line is missing for the requested locale, fill mode builds the message the §3.1 way — label written in, then looked up — rather than translating the line with its placeholder.

### `migrate` — the Langsys zero-file model

- `__()`, `trans()`, `@lang` and `trans_choice()` are answered from the Langsys catalog (§3.2).
- Validation messages are built from the rule that failed, with the label written in, and registered for translation, while Laravel's own rendering is left alone (§3.1).
- Entries are attached to responses in Laravel's native envelope, carried across redirects, and shared with Inertia (§5).
- Phrases the catalog lacks are registered after the response, under a write key only, through the existing flush path.

**Migrate mode and automatic mode never run together.** `TranslateResponse` would re-walk text `__()` already translated in migrate mode and register the translations as source phrases — the hazard already documented for `@t`. In migrate mode the middleware declines to run and reports it once.

`langsys.localization` decides which of Laravel's own services answers Laravel's localization calls. It does not change what the core does once it is asked. It is recorded under BIND-4 as framework wiring, for the Reviewer to rule on.

## 3. Migrate mode

### 3.0 The server never emits Langsys-translated text as part of this feature

**Operator ruling, 2026-09-15.** The server registers source phrases, the API machine-translates them, and the client renders `entry.template` through `t()`, falling back to `entry.message` (MSG-5). `message` is the base-locale fill, never a translation. So nothing Langsys translated leaves the server as part of server messages: not an entry's `message`, not the 422 body, not an Inertia prop.

*Why:* translated text where every reader expects source text breaks this feature's own flow, and the first reader it breaks is a client SDK — it looks the string up, misses, because the catalog is keyed by source, and registers a translation as a new phrase.

This is what separates a server message from an ordinary phrase. `t()`, `@t` and `__()` do translate on the server, because the client never needs their source. A server message carries its source to the client by design.

### 3.1 Validation messages — MSG-9, MSG-10, MSG-11

**The hook is a validator resolver.** In migrate mode the service provider calls `Validator::resolver()` with a `Validator` subclass. So every validator Laravel makes — FormRequest, `$request->validate()`, `Validator::make()`, Livewire's — builds messages this way, with no application code. If something replaces the resolver later, the package reports it once rather than failing silently.

**The subclass overrides one step: turning a failure into its message.** For each failure it has the rule name, the rule's parameters and the attribute. It never reads text Laravel already rendered (MSG-9):

1. **Label** (MSG-10). The label comes from Laravel's own label concept: `getDisplayableAttribute()`, which reads FormRequest `attributes()`, the fourth argument of `Validator::make()`, `setAttributeNames()`, and the implicit-attribute formatter. With no lang files there is no `validation.attributes`. A field with no declared label still gets Laravel's humanised key at runtime, so the sentence reads, and the build-time command reports it (§6).
2. **Wording.** A custom message wins, as in Laravel: FormRequest `messages()`, or the inline messages argument. Otherwise the wording is **Laravel's own English line** for the rule, read from the installed framework's `validation.php`, so defaults stay Laravel's defaults. Laravel's size variants apply: `min.string`, `min.numeric`, `min.array`, `min.file`.
3. **Template** (MSG-3, MSG-11). A classification table in this package decides, for each of Laravel's 107 rules, what each placeholder is:

   | Placeholder | Becomes | Why |
   |---|---|---|
   | `:attribute` | the field's label, written in | translatable, and it governs agreement |
   | `:other` | the other field's label, written in | same |
   | `:value`, `:values` naming fields or options | labels or option names, written in | translatable; a new option registers when first emitted (MSG-8) |
   | `:min` `:max` `:size` `:digits` `:decimal`, a numeric `:value` | `{min}` … markers, sent as numbers | not translatable; plurals are ICU's job (MSG-11) |
   | `:format` `:encoding`, a literal `:date`, literal `:values` lists (extensions, prefixes) | `{format}` … markers | not translatable |
   | `:input` `:index` `:position` | `{input}` … markers | the user's raw input, or a number |

   Laravel's case variants — `:Attribute`, `:ATTRIBUTE` — write the label in with the same casing. A tripwire test fails when the installed Laravel has a rule or placeholder the table does not classify, so an upgrade cannot quietly send an unclassified rule.
4. **Code** (MSG-2). The code comes from the shared vocabulary, per rule. Size rules pick the side of the bound and the field type, so a string is `too_short`/`too_long`, a number `too_small`/`too_large`, a list `too_few`/`too_many`. `between` and `size` pick the side by comparing the value against the rule's parameters — structure, not text. The core helper for this is *pending* (`forBound`).
5. **Entry.** `ServerMessage::make($code, $template, $params, $field)`, from the core; `$field` is Laravel's dotted attribute. The entry is recorded, and `$client->emitMessage($entry)` registers its template when the catalog lacks it — after the response, on the existing flush path, so the request is never blocked (MSG-8). Laravel's message bag is not rewritten.
6. **Laravel's surface does not change, and neither does its text.** `$errors`, `@error`, `$validator->errors()`, the 422 `errors` map and Livewire's error bag keep Laravel's own sentences, in the source language (§3.0). What migrate mode adds is the entries beside them, and registration of any template the catalog lacks.

**Rule objects and closures.** A `ValidationRule` that calls `$fail('The :attribute must be uppercase.')` fails under its class name, with text the author wrote. Its template is that text with the label written in, and its code is `invalid` — the vocabulary has no code for an application's own rule, and a code derived from a class name would change on rename, which MSG-2 forbids. Built-in rule objects (`Password`, `Enum`, and the `Rules\*` that stringify) are worded as the rule they stand for. `ValidationException::withMessages()` carries text and no rule, so it becomes `code: invalid` with that text as the template (MSG-9).

### 3.2 `__()`, `trans()`, `@lang` — zero files

In migrate mode the `translator` binding is extended with a subclass. Laravel's calling convention is unchanged; only where the line comes from differs.

1. **The source line.** A JSON-style key is its own source text: `__('Welcome back')`. A group key (`auth.failed`, `pagination.next`) resolves to Laravel's line in `app.fallback_locale`, taken from the framework's bundled English, which Laravel's loader always reads. With no application lang files, that is the only source a group key has.
2. **Translation.** The source line is looked up in the Langsys catalog in the request locale. On a miss, the source line is returned and queued for registration.
3. **Replacements.** Laravel's `:name` replacements are applied after translation, as today. They carry values — a name, a count — never translatable text. Part 2 lists the cases where an application puts translatable text in one.
4. **Plurals.** `trans_choice()` lines keep Laravel's pipe syntax as one phrase. New plural text is better written with `t()` and ICU.
5. **Validation lines** never go through this path; §3.1 owns them.
6. **An application group key** such as `messages.welcome` has no source text once its file is gone. Migrate mode returns the key, as Laravel does for any missing key, and registers nothing: a dotted key is not a sentence. Part 2 converts these keys first.

## 4. `:attribute` under MSG-3

**Today, Laravel translates `The :attribute field is required.`, then substitutes a separately translated label.** In Spanish that yields `El campo :attribute es obligatorio`, and whatever the label is, the sentence around it was inflected before the label was known — `Contraseña es obligatorio`. MSG-3 exists to remove exactly this.

**Migrate mode moves the substitution before translation, into the source language.** The label is written into the English sentence, and the sentence is the phrase: `The password field is required.` and `The name field is required.` are two catalog entries, each translated whole, so each agrees.

What that means for an application:

- **The `attributes` array keeps its meaning — the field's name as a person reads it — and its values stay in the source language.** A label is never translated on its own; it is translated as part of each sentence it appears in. FormRequest `attributes()` is where labels live, and is what MSG-10 means by the framework's label concept.
- **`:attribute` in custom messages works the same way:** `messages()` stays by the book, and the label is written into each sentence.
- **Translation volume grows from one phrase per rule to one per field per rule.** The build-time command lists and registers all of them ahead of time (§6), so none reaches a user untranslated.
- **Markers are only for values** — numbers, dates, formats, raw input. `The password field must be at least {min} characters.` is one phrase for every minimum. A language that inflects around the number does it in ICU (MSG-11).
- **One case stays open:** a non-translatable proper noun that governs agreement, such as a person's name in a message. Its fix waits on gender-select (#827), and the plan records it rather than working around it.

**Enumeration has no exceptions.** Laravel's wording stays exactly as Laravel writes it, including the word "field", so a Spanish sentence anchors its agreement on *campo* rather than on the label. That is correct, if wooden, and it is not a reason to skip enumerating: the label is written into every sentence and one phrase is registered per field, for every rule. A shortcut that kept `:attribute` as a placeholder wherever the wording happened to make agreement safe would behave differently from every other rule, break the moment the wording changed, and put a translatable value in a marker, which MSG-3 forbids outright.

Keep mode leaves `:attribute` exactly as Laravel has it, agreement problem included. That is what "keeps working exactly as it is" means.

## 5. Where entries go — MSG-1, MSG-12

The entry is fixed: `{field?, code, message, template, params?}`. The envelope stays Laravel's own.

- **JSON 422.** `{message, errors}` keeps its shape, and `errors` keeps Laravel's own text in the source language. The entries go beside them under one configured key, `langsys.messages.response_key`, default `langsys_errors`. A response middleware, appended to the `web` and `api` groups in migrate mode only, reads the `ValidationException` Laravel's pipeline attaches to the response (`$response->exception`), and takes the entries from its validator.
- **Redirects.** For a form that fails and redirects, the entries are flashed to the session under the same key, beside Laravel's own `errors` bag. A Blade page rendering `$errors` shows the source language; a page with a JS SDK renders the entries.
- **Inertia (MSG-12).** When `inertiajs/inertia-laravel` is installed, the provider shares the flashed entries as a page prop under the same key, so the destination page's JS SDK can render them through MSG-5. Inertia's own `errors` prop is untouched, and carries source text.
- **Category (MSG-6).** `langsys.messages.category`, default `Errors`, is passed straight to the core's `messages_category`. Rendering, runtime registration and the build-time command all use it.
- **Not yet planned: system messages** — `abort()`, authorization and HTTP exceptions. Their text is not declared anywhere the command can list it. They are a later phase, and would reach the same key as text-only entries.

## 6. Build-time command — MSG-7

`php artisan langsys:messages [--register]` wraps the core's catalog command and prints through Laravel's console output. It exits non-zero on any problem, which is what puts it in CI.

| Source | Lists | Reports |
|---|---|---|
| FormRequests — from route action signatures, and `app/Http/Requests` | every rule × its field's label × the wording table | a field with no label (MSG-10); a rule the table does not word; `rules()` that cannot be built without a live request; a closure rule |
| Rule objects | the literal strings passed to `$fail()`, read from the class's tokens, with each field's label written in | a `$fail()` whose text is built at runtime |
| laravel-data classes, when installed | as FormRequests | as FormRequests |
| Error classes, through the core's `ErrorClassSource` | `CODE` plus `MESSAGE`/`TEMPLATE` | a marker with nothing to fill it |

`--register` is idempotent: it registers only templates the catalog lacks, under the configured category. **Not listed:** rules written inline with `$request->validate([...])` or `Validator::make()` in a controller. They are covered at runtime by MSG-8, and Part 2 recommends a FormRequest for anything that should be ready on day one.

## 7. Order of work

Each step is red-first: the test fails against the tree before the change.

1. **Wording table and normalizer** (MSG-9, MSG-10, MSG-11): the classification of Laravel's `validation.php`, with the upgrade tripwire, and entries built from a failed validator.
2. **Validator hook** in migrate mode, entries recorded and templates registered, and **a keep-mode test that responses are byte-identical** with and without the package.
3. **Envelope** (MSG-1): JSON, redirect flash and Inertia share (MSG-12), with Inertia installed as a dev dependency for the tests.
4. **Migrate-mode translator** for `__()`, `trans()`, `@lang` and `trans_choice()`.
5. **`langsys:messages`** (MSG-7) and its sources.
6. **`CONFORMANCE.md`** re-derived against blob `f8ff6e1e`, all 91 ids, with MSG-1 to MSG-12 rowed and the core rows delegated to PHP's. Mutations run in a `git worktree`.

Steps 1, 2 and 4 depend only on the core's `ServerMessage`, `emitMessage()` and the category option; steps 5 and 6 need the rest of the core API.

## 8. Decisions and open questions

**Decided by the operator, 2026-09-14:**
- **Keep mode is the default, and emits nothing.** No entries, no session flash, no Inertia prop, no validator or translator replacement; a 422 body is byte-identical to the application without this package. Langsys-translated validation messages are therefore a migrate-mode feature, by design.
- **Migrate-mode wording is Laravel's own English**, read from the installed framework's `validation.php`, so all 107 rules have wording and the defaults are Laravel's defaults. When a Laravel release rewords a line, that sentence becomes a new phrase: the command lists it, `--register` registers it, and it is translated fresh.
- **Importing existing translations is deferred.** Part 2 documents what carries over and what is translated fresh. An import command waits for the core to offer bulk registration with translations.
- **System messages are a later phase** (§5).

**Decided by the operator, 2026-09-15:**
- **The server never emits Langsys-translated text as part of server messages** (§3.0). An earlier draft of this plan, and the first implementation, put the translated sentence into Laravel's message bag. Both are corrected, and a test pins it.
- **Wording stays Laravel's own, unchanged**, including the word "field". Dropping it would let each label govern agreement in translation, but it would also move away from Laravel's defaults, and the gain is readability rather than correctness.
- **Enumeration is unconditional** (§4). No rule, and no phrasing, is exempt.
- **`fill` is a third mode** (§2), explicit and gap-only.
- **The client-side registration hazard is raised, and ruled on.** A client handed already-translated text can register it as a new source phrase, because the catalog is keyed by source. The fix is fleet-wide: the TS core gets a base-locale registration gate as a setting defaulting to off, and producers emit an "already resolved" marker — a DOM attribute for server-rendered hosts, a shape for strings in JSON props, with TypeScript owning both. This package consumes that marker wherever it renders translated text: `t()`, `@t`, and `__()` gaps in fill mode. Server messages need none of it, since entries carry source text and the client renders it.
- **Blade-only pages in migrate mode are not ruled yet.** A page with no JS SDK shows source-language validation messages, because there is no client to render the translation. That behaviour stands as documented, and server-side validation translation is not to be built until the operator rules; the marker above is what would make it safe if it comes.

**Decided in this plan, for review:**
- An application's own rule object gets `code: invalid`.
- Entries go beside Laravel's body, under `langsys_errors`.
- `langsys.localization` counts as framework wiring under BIND-4.

**Open:**
- **Option labels without files.** Laravel keeps display names for option values only in `validation.values` lang lines. Zero-file needs them declared in code: `setValueNames()` in a FormRequest's `withValidator()` works today, but it is not a common pattern.

---

# Part 2 — Migrating from lang files to zero-file

This moves an application from `lang/*` files and `validation.php` to the Langsys zero-file model. Every step is reversible until the last one. **Setting `LANGSYS_LOCALIZATION=keep` restores Laravel's behaviour at any point**, provided the lang files are still in git.

**Before you start:** the package is installed and configured (`LANGSYS_API_KEY`, `LANGSYS_PROJECT_ID`). Use a **write** key in development and in the CI job that registers phrases, and a **read-only** key in production.

### Step 1 — Take stock

```bash
php artisan langsys:messages -v
```

The command lists every message your validation can produce, and every problem that would stop one being translated ahead of time. It works in either mode and changes nothing. Keep the output: the steps below clear its problem lines.

### Step 2 — Move field labels into your requests

In zero-file mode there is no `validation.attributes`. Each label moves to the request that validates the field, **in your source language**:

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

### Step 5 — Give every translatable string its source text as its key

Zero-file keeps no mapping from `messages.welcome` to a sentence. So every **application** key has to become its own source text:

```php
// Before
__('messages.welcome', ['name' => $user->name])   // lang/en/messages.php: 'welcome' => 'Welcome back, :name'

// After
__('Welcome back, :name', ['name' => $user->name])
```

Laravel's own groups — `auth`, `pagination`, `passwords` — need no change: their English ships with the framework.

### Step 6 — Keep replacements for values only

A `:name` replacement is filled *after* translation, so it must never hold translatable text:

```php
__('Welcome back, :name', ['name' => $user->name])   // fine: a person's name
__('Your :plan plan renews soon', ['plan' => 'Pro']) // fine: a product name you don't translate
__('The :thing was deleted', ['thing' => 'invoice']) // not fine: "invoice" never reaches a translator
```

Write each translatable variant as its own sentence instead — `The invoice was deleted.`, `The project was deleted.` A variant your code discovers at runtime registers the first time it is shown.

### Step 7 — Decide about pipe plurals

`trans_choice('{0} No items|{1} One item|[2,*] :count items', $n)` keeps working: the whole pipe string is one phrase. For new text, prefer `t()` with an ICU plural, `{count, plural, one {# item} other {# items}}`, which every Langsys SDK renders the same way.

### Step 8 — Register ahead of time

```bash
php artisan langsys:messages --register
```

Run it in CI with a write key. It fails the build on any problem left from step 1, and registers every validation sentence so it is translated before a user sees it. It is safe to run repeatedly.

### Step 9 — Carry over existing translations

Sentences that were whole lines in your lang files — JSON keys, and the group lines converted in step 5 — can bring their translations with them. This package does not import them yet, so enter or upload them in the Translation Manager, or let machine translation fill them in and review. Validation messages cannot: `The card number field is required.` is a new sentence with its label written in, so it is translated fresh — by machine translation, then reviewed in the Translation Manager. This is the one place migration costs translation work. It is the price of correct agreement.

### Step 10 — Switch, and check

```dotenv
LANGSYS_LOCALIZATION=migrate
```

Run your test suite. Then check one failing form in the browser, and one failing JSON request:

- The 422 body still has `message` and `errors`, in your source language, plus `langsys_errors` entries for a client to translate.
- A redirecting form still shows `$errors` in Blade.
- An Inertia page receives `langsys_errors` as a prop.

### Step 10a — Or stop at `fill`

If you want your existing translations to keep answering and Langsys only to cover what they miss, set `LANGSYS_LOCALIZATION=fill` instead and keep your lang files. Steps 1 to 4 still apply, because a validation message with no line for the requested locale is built the same way. Nothing else in this guide is required.

### Step 11 — Delete the lang files

Once the application behaves as it should in `migrate`, remove `lang/`. **To roll back:** restore it from git and set `LANGSYS_LOCALIZATION=keep`.

### What you don't have to change

- FormRequests, `$request->validate()`, `Validator::make()`, Livewire validation.
- Blade's `$errors` and `@error`.
- Your 422 JSON handling.
- `__()`, `trans()` and `@lang` with source-text keys.
- `t()` and `@t`.

Your application keeps writing Laravel. Only where the words come from changes.
