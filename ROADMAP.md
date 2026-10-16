# Roadmap / known gaps

Running list of deferred work and design decisions for
`langsys/langsys-php-laravel`, so the context isn't lost between sessions.

## 838: conformance to the SDK spec (8.5.9)

Graded row by row in `CONFORMANCE.md`. The design decisions and deferred work behind those rows are recorded here.

### Release gate — the core is untagged, and the constraint has to move at publication

This branch depends on the 838 `langsys/langsys-php` core (`e76ff40`): `resetRequestState()`, `resolveRequestLocale()`, `resolve()`, `translateRich()`, `markResolved()`, `useMissFallback()`, `planSync()` / `applySync()`, the value-set declarations, the rule-object and app-message templates, the offline planner, the server-message API, the snapshot seam, the client without credentials and its `require_credentials` option, an excluded element kept whole inside a phrase, a scanner that reads a rich line and a rich choice, the reference-list reads, script and style bodies kept byte for byte, and `translate()` / `translatePage()` that never throw. v1.3.1 has none of that. `composer.json` still says `^1.3`, which resolves to v1.3.1 from Packagist, so:

- **CI fails on this branch by design.** It installs from Packagist, and against v1.3.1 the suite calls core methods that release does not have.
- **At publication**, the constraint moves to the core's 838 tag and the local path repository (below) goes. The operator publishes every SDK at once, after all of them are green.

### Decided: the local path repository stays uncommitted

For local development, `composer.json` carries a `repositories` path entry pointing at `../langsys-php-sdk` with `symlink: true`, plus a `versions` override pinning `langsys/langsys-php` to `1.3.1`. It is never committed, and the mesh's end-of-day sweep carries it as a standing exception.

- **The path is machine-specific.**
- **The `versions` override is a local fiction.** The checkout sits on an untagged feature branch past v1.3.1, and without the override it resolves as `dev-*` and fails `^1.3`. Committed, it would let anyone who clones this repo beside their own `langsys-php-sdk` build silently against whatever uncommitted state that tree holds, believing they had the Packagist release.
- **Consumers are unaffected either way.** Composer ignores a dependency's `repositories`, so the hazard is to developers of this package, not users of it.
- **It is also a live integration check.** The SDK's uncommitted edits run in this suite without a reinstall, so a core change that breaks the wrapper shows up here before it is tagged.

### Decided: three 1.x behaviours change in 2.0.0

The operator ruled on each; `CHANGELOG.md` and `UPGRADING.md` carry them as 2.0.0's behaviour.

**`auto_flush` is removed.** It was product configuration the core does not define (BIND-4), and under PHP-FPM it never stopped a flush, because the core's shutdown handler sends the queue regardless. The long-lived boundaries flush and then reset unconditionally; the reset must never be guarded by any setting, or GATE-3 and SRV-2 regress.

**The `TranslateResponse` page cache is removed, not keyed by project.** A project id in the key would have fixed CACHE-1 and still broken BIND-5, which is flat: *a binding does not cache lookup results*. The cache also served a translation for its TTL after the SDK's catalog refreshed, and a hit fed no registration lane (GATE-7). If a customer ever needs one, it comes back as a recorded BIND-5 waiver: keyed `prefix . project_id . ':page:' . locale . ':' . sha1(html)`, both keys added to `BindingBoundaryTest::CONFIG_SURFACE` as waived, BIND-5 regraded `waived`, GATE-7's cache-hit limit recorded, and a two-project CACHE-1 test.

**`initialTranslationsLocale` is lowercase** (`es-es`), the form both SDKs identify a locale by (WIRE-3). The JS core canonicalizes whatever it is handed, so the hand-off behaves the same; the upgrade notes call it out for apps that read the prop themselves.

### Decided: failure handling is delegated around every lookup; six sites degrade themselves

`LangsysTranslator`, `TranslateResponse`, `FlushPendingRegistrations` and the long-lived boundary listeners catch nothing around the core: `translate()`, `translatePage()` and `flushPendingRegistrations()` catch every `\Throwable` themselves (measured with a throwing cache and an unreachable API), so a binding-side catch could only drift from the core's. Tests pin that the wrapper does not swallow what the SDK lets through.

**`InertiaSsrProps::share()` keeps a catch, because nothing upstream can own it.** `getTranslations()` throws by design — answering an outage with `[]` would cache an empty catalog — and `share()` sits on every Inertia request. On failure it hands no seed rather than an empty one: the JS SDK marks a seeded locale loaded and skips its fetch for 60 seconds.

**Five more sites degrade where no lookup is involved.** The provider builds the client without a snapshot that fails to load, and reports it; discovery passes over a class in app/ that cannot load in this install, such as a dev-only package's provider; `DetectLocale` leaves Laravel's locale alone when the project's locales cannot be read and no snapshot is loaded; `AttachServerMessages` names no `Content-Language` for a source `message` when the project's base locale cannot be read; and `MessageValidator` lets a form fail as Laravel's own when building its entries fails, since Laravel's messages stand either way. The three that call the core rethrow its `ConfigurationException`, which `require_credentials` asks to fail the request.

### Deferred: route the core's logger to a Laravel log channel

The core accepts a PSR-3 `logger` option, and Laravel's log manager is one. Passing a configured channel through would be pure shape adaptation (BIND-1), and it would make the core's diagnostics visible where a Laravel developer looks. It is deferred rather than done because it changes log volume — the core logs a debug line per catalog hit — which is a product decision. Without it, the core's warnings and errors go to PHP's error log.

### Known limits

- **Octane is exercised through stand-in event classes**, `WorkerStarting`, `RequestTerminated`, `TaskTerminated` and `TickTerminated`, each carrying what Octane's own does. Octane itself needs a Swoole, RoadRunner or FrankenPHP server, so it is not installed. The provider listens by class name, which is what the tests pin.
- **`LaravelCacheAdapter`'s key index is read-modify-write.** Two concurrent first writes can each drop the other's key from the index, and `clear()` then misses that key until it expires. No spec rule covers it.

## Request locale (SRV-6)

### Decided: Laravel's locale first; DetectLocale resolves only where nothing did

The locale is Laravel's. The provider listens for Laravel's `LocaleUpdated` event and marks the request, so `DetectLocale` knows whether anything set the locale before it ran. Comparing `app()->getLocale()` against `app.locale` cannot tell, because `setLocale()` rewrites that config value, and it would miss an app that sets its default explicitly. Where the app set it, Laravel's locale is left alone and the SDK maps it (`resolveRequestLocale(['framework' => …])`): an exact match, a bare language to the project's default locale for it (`default_locales`), otherwise the base locale, and offline from a loaded snapshot's locales. `DetectLocale` records the mapping on the request, and every lookup, the resolved-page marker and the page walk run in the served locale while Laravel's locale stays as the app set it (`ClientState::locale()`); the lang-file fallback still reads Laravel's locale, so an unserved locale prints the app's own line. No `Vary` is added: the choice is the app's to vary on. Where nothing set it, `DetectLocale` reads `sources` in the app's order, validates each candidate against the project's locales narrowed by `supported` (a bare language through `default_locales` too), offline against a loaded snapshot's base and locales, negotiates `Accept-Language` with the SDK, and adds the `Vary` its choice depended on. The Client is built with `send_vary => false`, so the SDK's own fallback never sends a raw `header()` that Laravel's response doesn't carry.

## Laravel's translate function (FRM)

### Decided: always on, one off switch

Installed, the package answers `__()`, `trans()`, `trans_choice()` and `@lang` (FRM-1). `langsys.enabled` (`LANGSYS_ENABLED`) turns all of it off for debugging: Laravel's own translator and validator, no entries attached, and the package's middleware on a route passes the request through. Compiled views do not depend on the switch, since Laravel keeps a compiled view until its template changes: `@lang`, `@t` and `@choice` compile to `getHtml()` and `choiceHtml()`, which Laravel's own translator answers through a macro when off, and a marked value prints as Blade prints it. `t()` is `__()` by another name.

### Decided: sync registers what the app can show

`__()` registers nothing while serving a request (FRM-2), apart from a sentence written in from a declared value the last sync did not see (FRM-7); `langsys:sync` registers what the app's code and lang files hold, with the lang files' translations. The page walk (`TranslateResponse`) registers what it meets after the response. Blade is compiled for scanning by a copy of the app's own compiler — its directives, components and precompilers, the value marker left out — so a line compiles to `app('translator')->getHtml()` or `choiceHtml()`, which the core's scanner reads as a rich line and registers in the form the page looks it up by, a call inside the app's own Blade syntax is read too, and a scan never logs what rendering would. A compiled hit takes the line of its own call in the view — the n-th call of a function in the compiled view is its n-th in the source — because Blade drops comments and rewrites directives.

### Decided: a rule object states its template through the core's interface

Laravel's rule contract hands the validator a finished message, so a listing cannot tell where a value goes. A rule object implementing `Langsys\SDK\Messages\HasMessageTemplate` is listed from `template()` through the core's `MessageCatalog::addRule()`, and at runtime the binding finds the failed rule's instance in `$validator->getRules()` — `failed()` keeps only its class — and sends `RuleTemplate::forField()`'s template and params, so runtime and listing read the same method. The label is Laravel's `getDisplayableAttribute()` at both sites. A rule object without the interface is listed from its filled message and fails `--strict`; one that declares no message ahead of time (`Password`) is reported with the same fix.

### Decided: mail sent from a client page is recognised on the call stack

Mail and notifications are recognised from the call stack. Laravel has no event before a `Mailable` or a mailed view renders, a notification a listener cancels fires none after `NotificationSending`, and `LocaleUpdated` fires only for a Mailable carrying a locale and for the app's own `setLocale()` alike, so no event can say. `ResponseKind::forRecipient()` reads the frames instead: a `Mailable`, Laravel's `Mailer` contract, `Mail\Markdown`, the `NotificationSender` or a queued job's handler (`CallQueuedHandler`) means what is rendering is read by a recipient, a job's output included when it runs inside the request. On a CLIENT response `__()` then translates, and a Blade view, asking once per render, marks no values. The whole stack is read, since each nested view or component adds frames between mail and the call — measured, about nine per component and eight per `@include` — and Blade asks once per tree of views, at the outermost one.

### Decided: app messages are discovered as value sets are

A message the app defines itself implements the core's `HasAppMessageTemplate` and is found by the same token scan of app/ as FRM-7's declarations (`ClassDiscovery`), plus `langsys.messages.classes`; `langsys:cache` caches both. The core lists each (`MessageCatalog::addMessage()`), builds a class without its constructor and checks its markers against declared properties; a backed enum lists each case. A Laravel rule that also implements the contract stays a rule, listed per field. `langsys:sync` hands the core's plan the discovered classes and the templates the listing lists, so the `__()` that states a listed message never registers as a bare phrase too.

### Decided: Blade marks printed values; it ships with its readers

A Blade precompiler routes every `{{ }}` in visible text through `ValueMarker::value()`, which wraps the escaped value in `<!--ls:NAME-->…<!--/ls-->` (VAR-3, VAR-5); names follow the shared VAR-2 table (`PlaceholderNames`, the fleet's vectors executed in the tests). Attributes, raw-text elements, comments, `{!! !!}`, `Htmlable` values and translation calls are never marked. The echo stays a Blade echo, so Laravel's escaping, double encoding and echo handlers apply. VAR-4: it ships in the wave that releases the PHP and TypeScript readers, never before.

### Not built: two guards

- **`TranslateResponse` and `__()` on one route.** The two must not run together (the page walk would register `__()`'s translations as source), and the middleware could detect the combination and decline, reporting it once. Today it is documented as a project's choice, as tagged and automatic mode are.
- **A replaced validator resolver.** Something that installs its own `Validator::resolver()` after this package silently turns entries off. Reporting that once would make it visible.

### Decided: Laravel's JSON files are declared `laravel`

Each migration file names its format (MIG-7). A PHP array defaults to `laravel`, and a JSON file to `plain`, where a `|` plural stays text. The app's `{locale}.json` and every package JSON path hold Laravel's syntax, so `MigrationFiles::for()` declares them `laravel`; group files take the default.

### Decided: a sentence passed as its own key is converted by the call that received it

A `__()` argument no file holds is literal source text (MIG-2), written in the syntax of the Laravel call that received it, while the core reads a literal as Langsys syntax. So `CatalogTranslator` converts it with the core's `LegacyValue::fromCall()` for its entry point and hands `resolve()` the result. `__()` converts only the `:key` placeholders it is passed and leaves any other `:word`, and any `|`, as Laravel prints them. `trans_choice()` also reads `|` as Laravel's plural. The table is the core's and the spec's (MIG-2); the binding supplies only which call it was.

## Coverage model: explicit tagging vs. automatic translation

**Tagged mode (the default)** translates only what goes through Laravel's translate function:
`__()`, `trans()`, `trans_choice()`, `@lang` and their aliases `t()` / `@t`. Untagged output is
passed through untouched, so coverage is exactly as complete as the tagging. It fully covers:

- **Blade** — every tagged string; the natural Blade idiom.
- **Livewire** — Blade views + `t()` in component classes; proven end-to-end in
  `tests/LivewireSupportTest.php`.
- **Alpine's DOM-text content** — e.g. `<span x-show="open">@t('Details')</span>`,
  where the text is a real DOM node you can wrap.

**What it cannot reach — Alpine's dynamic/attribute content.** Text Alpine injects from a JS
expression never becomes a DOM text node you can tag:

```blade
<span x-text="'Save changes'"></span>              {{-- ✗ visible text is a JS literal --}}
<button :aria-label="open ? 'Collapse' : 'Expand'">…</button>  {{-- ✗ --}}
```

Wrapping `@t` inside the JS string (`x-text="'@t(...)'"`) is fragile: `@t` prints HTML through
`CatalogTranslator::getHtml()` — catalog text escaped, the app's own lang-file text raw — not a
JavaScript string literal, so a translation containing a quote breaks the expression.

**Automatic mode** (`TranslateResponse`, below) walks the rendered HTML and covers that text too.
A project runs one mode, never both.

## Automatic mode: the `TranslateResponse` middleware

`src/Http/Middleware/TranslateResponse.php`, alias `langsys.translate-page`, config block
`translate_response`, tested in `tests/TranslateResponseTest.php` and
`tests/TranslateResponseSafetyTest.php`.

It runs `Client::translatePage()` (+ `HtmlParser`, `SelectorMatcher`, `MarkupTokenizer`, the
translatable-attributes config) — the **server-side analog of the JS SDK's DOM tokenizer** — over
the final rendered HTML, so server-rendered Laravel (Blade, Livewire, Alpine) is covered without
hand-tagging: every text node and translatable attribute is translated, and write-key token
discovery runs over the whole page.

Design:

- **Opt-in, scoped.** Only `text/html`; JSON, redirects, streamed and file responses pass through.
  Registered as an alias only — never added to a group, because automatic mode must not switch
  itself on for a project that tags with `@t`. `only`/`except` path patterns narrow it further;
  `except` wins. The content-type guard is what excludes Livewire and Inertia XHR round-trips
  without the middleware knowing those libraries exist.
- **Respect `translate="no"`** and the SDK's translatable-attributes config (both `translatePage`'s).
- **Double translation: one mode per project.** Automatic *or* tagged, never both. A node `@t`
  already translated is re-walked by `translatePage()`, looked up as a source phrase, missed, and
  **registered** — so a Spanish `"Guardar"` enters the catalog every Langsys SDK shares, as if it
  were a source phrase: `<p>Save</p><p>Guardar</p>` registers `["T", "Save", "Guardar"]`.

  A wrapper-side marker attribute cannot prevent it. `@t` emits inline text and is used **inside
  attribute values** (`<input placeholder="@t('Your name')">`), where there is no element to mark;
  `translate="no"` on the `<input>` would exclude attributes we *do* want translated. A skip
  mechanism that covers text nodes but silently misses attribute positions is worse than none — it
  works until someone translates a placeholder.
- **`data-notrans` excludes on presence.** Bare `data-notrans`, `=""` and `="true"` exclude; only
  `"false"` and `"0"`, trimmed and case-insensitive, opt back in. Ten cases are pinned in
  `TranslateResponseSafetyTest::testExclusionMarkersAreHonouredOnRegistration`, asserted on the
  registration list rather than the rendered output, because an exclusion bug reaches the shared
  catalog before anything looks wrong on screen.
- **`translate="no"` is the per-subtree escape hatch.** Honoured by `HtmlParser`, `PageTranslator`
  and `MarkupTokenizer`, and the cross-SDK answer — the JS tokenizer checks it beside its own marker
  check. Standards-based HTML, no new vocabulary to keep in sync: `<p translate="no">Guardar</p>`
  is not extracted, not registered, not rendered over. **Do not invent a wrapper-side skip
  marker** — the capability exists under a name the platform already gave us.
- **Inline `<script>` / `<style>` survive byte for byte.** A response middleware runs over *whole
  rendered responses*, which is exactly where bootstrapped JSON state, CSRF tokens and inline JS
  live, and a catalog entry that could rewrite inline JS would be a stored-XSS shape.
  `TranslateResponseSafetyTest` runs the **real** page translator (with `getTranslations()`,
  `canWrite()`, `getLocale()` and `flushPendingRegistrations()` stubbed, to stay off the network) over a Laravel-shaped page and asserts that script
  and style bodies — markup written inside them included — come back byte for byte, both CSRF
  token copies are intact, nothing from a script or style is registered, and `translate="no"` is
  honoured, while an untranslated heading *is* registered, so the guard can't pass vacuously. Keep
  that test honest if the page fixture changes.
- **Registration happens after the response.** `translatePage()` queues what it meets through the
  core's pending-registration queue, so `FlushPendingRegistrations`, the long-lived boundaries and
  the core's shutdown flush send it once the response is out; nothing is registered mid-render.
  Translations registered by a request appear on the next page load, not in the same response.
- **`data-langsys-phrase` keeps a run together on every path.** On the page walk and inside a
  content block alike, a marked run is one tokenized phrase (core MARK-2, MARK-4).

**Testing an exclusion.** Read `Client::getPendingPhrases()` — the boundary that decides what
reaches the catalog — rather than overriding a transport method, and pair every absence assertion
with a positive control (an unmarked subtree MUST be queued). A capture point that stops recording
then fails loudly instead of letting every `assertNotContains` pass vacuously. The SDK's
`register_shutdown_function` flushes whatever is still queued at process exit, so a test that
deliberately leaves phrases queued stubs `flushPendingRegistrations()`; otherwise the suite POSTs
them for real, which against a developer's real credentials would write test fixtures into a live
shared catalog.

**The boundary that keeps this a wrapper.** The middleware may decide **whether** to call
`translatePage()` and **what to hand it**. It must never decide **what inside the HTML** gets
translated — which elements are walked, which attributes are translatable, what `translate="no"`
means, how markup is tokenized. Those are upstream's, permanently. The moment wrapper code inspects
the HTML itself it has stopped being a wrapper. It caches nothing either: a translated page is not
a binding's to keep (BIND-5).

## Decided: keep `illuminate ^10.0 || ^11.0 || ^12.0`

**Decision (Darryl, 2026-08-18): keep the constraint as it stands.**

Every `laravel/framework` release in the **10.x and 11.x** lines now carries an
open, unfixed security advisory — `CVE-2026-48019` among them, whose affected
range enumerates `>=10.0.0,<11.0.0` and `>=11.0.0,<12.0.0` with no fixed version
in either branch, because both are past security support. **Composer 2.9 refuses
to lock advisory-flagged packages by default**, so `composer update` fails
outright on those lines. Verified: with `audit.block-insecure false` they resolve
cleanly (10.50.3, 11.55.1) and the full suite passes, so this is a policy block, not
an incompatibility. Note that `--no-audit` does **not** cover it —
`audit.block-insecure` is a separate switch.

`ci.yml` lifts the block on exactly those legs, so we test what the manifest
promises, and Laravel 12 keeps the audit on so a future advisory against a
supported branch still fails the build.

**Consumer impact, measured rather than inferred** (scratch projects, 2026-08-16).
The block is narrower than "Laravel 10 users cannot install this":

| scenario | result |
| --- | --- |
| `composer require langsys/langsys-php-laravel` into an **existing** Laravel 10 app with a lock file | **works** — installs v1.0.0 cleanly, Composer only *warns* "Found 3 security vulnerability advisories" |
| `composer update laravel/framework` on that same app | **blocked** |
| **fresh** `laravel/framework: ^10.0` install with no lock | **blocked** |

Composer blocks *locking* an advisory-flagged version, not *having* one. An app
already on 10.x isn't re-resolving the framework when it adds a package, so our
actual consumers are unaffected. What's blocked is starting a new Laravel 10/11
project or bumping the framework within those lines — neither of which is
something we'd be enabling anyway.

**Why keep it.** Narrowing to `^12.0` would force a major on us and strand
existing 10.x/11.x apps that install and run this package correctly today — CI
proves the full suite green on both lines on every pull request and every push to `main`. The manifest describes what
the *code* supports; it was never a claim that Composer's audit policy will let
someone build a new EOL project. Dropping support because a third party's audit
policy blocks a scenario we don't enable would be inventing a limitation, not
documenting one.

**Revisit if** upstream Laravel publishes an advisory whose range covers 12.x
with no fixed version. That would change *who* is affected — from "people
starting new EOL projects" to "everyone" — and the calculus with it.

CI must follow the manifest, not diverge from it: the Laravel 10/11 legs lift
`audit.block-insecure` precisely so we keep testing what we promise. If the
manifest ever narrows, those legs go with it.

## Decided: CI runs the matrix, the lowest dependencies, and a parse of every file

`.github/workflows/ci.yml` runs the suite on 10 legs (PHP 8.1–8.4 × Laravel 10/11/12, less the
two Laravel lines that need PHP 8.2), a `lowest` leg on the oldest dependencies the constraints
allow, and a `lint` job. Three guards are load-bearing:

- **`--fail-on-skipped`.** The catalog-pollution guard (`TranslateResponseSafetyTest`) protects an
  irreversible failure: a phrase wrongly registered into the shared catalog cannot be
  un-registered by a later fix. A green run with that test skipped would report a guard that never
  ran.
- **An explicit `ext-intl` check.** setup-php's `extensions:` *adds to* the runner image rather
  than pinning it, so a misspelt name is silently ignored.
- **A parse of every source and test file on the 8.1 floor.** Running the suite does not subsume
  linting: PHP parses a file only when something loads it, and under PSR-4 a class no test reaches
  is never compiled, so newer syntax in it survives a fully green matrix. Execution covers only the
  set actually loaded, and nothing pins that set.

The same blind spot is why `tests/FacadeTest.php` exists. A wrong `getFacadeAccessor()` is valid
PHP: it parses, it breaks no other test, and the `@method` docblock documenting the API is a
comment enforced by nothing, there being no static analysis configured here. The tests assert the
wiring, and pointing the accessor at `Client::class` — a binding that still exists in the
container — fails them.

## Decided: ICU rendering is the core's

A catalog value can carry an ICU argument the source phrase never had: the ICU promoter turns
`{name}` into `{name_gender, select, …}` for gendered locales, so a call cannot supply every
argument. The core renders a missing argument as the construct's `other` branch, with and without
`ext-intl` alike: `Bienvenide Sarah` for a `select` with no gender, `{count} items` for a `plural`
with no count. The asymmetry is deliberate — `other` is the right branch for an unknown gender, and
nothing can infer a count, so the plural keeps its sentence and shows the gap. A wrong render is
fixed in `langsys/langsys-php`; this package never interpolates, and does not get a
wrapper-side `Interpolator` to work around one.

## Cross-SDK notes

- `%name%` markup placeholders (base JS SDK `langsys-js-typescript` ^0.4.1) are a
  JS-framework-compiler concern and **do not apply to Blade** (single `{name}` is
  literal in Blade). Interpolation lives in `langsys/langsys-php` and matches the
  canonical `{name}` form, so Laravel output stays consistent with the JS SDKs by
  construction rather than by our keeping a port in sync.
- **PHP SSR + JS hydration needs a JS SDK that knows `data-langsys-phrase`.** PHP's
  author-facing `data-langsys-phrase` survives into `translatePage()` output, and a JS SDK
  hydrating that page walks the same DOM. Its tokenizer must skip a subtree PHP already tokenized:
  `langsys-js-typescript` 0.6.2 and newer do, through `PHRASE_MARKER_ATTRS =
  ['data-ls-phrase', 'data-langsys-phrase']` and the exported `isPhraseMarked()`, which a wrapper
  tokenizing through its own templating can import rather than re-derive the list. An older JS SDK
  recurses *per text node* and **splits the run at tag boundaries**:

  ```
  <p data-langsys-phrase>Read the <a href="/d">docs</a> now</p>
    -> registers THREE fragments: ["Read the", "docs", "now"]
  ```

  That is exactly the fragmentation the keep-together primitive exists to prevent — a count and
  the noun it inflects land in separate catalog entries where no ICU plural can reach across
  them. The README states the version pairing; it fails silently, so it has to be stated.
- **Inline markup is tokenized as the JS SDK tokenizes it.** `MarkupTokenizer` encodes inline
  markup as `{m<i>o}`/`{m<i>c}` tokens, wire-format-identical to the JS SDK's `<Phrase>`
  component, so a subtree marked `data-langsys-phrase` registers as ONE phrase instead of
  splitting the count away from the noun it inflects. It is driven by an HTML attribute, not an
  argument, so `translatePage($html, $category, $selectorCategories, $params)` keeps its
  signature.
