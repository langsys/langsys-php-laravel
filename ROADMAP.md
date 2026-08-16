# Roadmap / known gaps

Running list of deferred work and design decisions for
`langsys/langsys-php-laravel`, so the context isn't lost between sessions.

## Coverage model: explicit tagging vs. automatic translation

**What we built:** the SDK translates only strings you explicitly wrap in
`@t('…')` / `t('…')`. There is no pass that walks the rendered HTML and
translates everything — untagged output is passed through untouched. Coverage
is exactly as complete as your tagging.

This is a deliberate choice and it fully covers:

- **Blade** — every tagged string; the natural Blade idiom.
- **Livewire** — Blade views + `t()` in component classes; proven end-to-end in
  `tests/LivewireSupportTest.php`.
- **Alpine's DOM-text content** — e.g. `<span x-show="open">@t('Details','UI')</span>`,
  where the text is a real DOM node you can wrap.

**The gap — Alpine's dynamic/attribute content.** Text Alpine injects from a JS
expression never becomes a DOM text node you can tag:

```blade
<span x-text="'Save changes'"></span>              {{-- ✗ visible text is a JS literal --}}
<button :aria-label="open ? 'Collapse' : 'Expand'">…</button>  {{-- ✗ --}}
```

Wrapping with `@t` inside the JS-string attribute (`x-text="'@t(...)'"`) is
fragile: `@t` HTML-escapes via `e()`, so a translation containing a quote or `&`
breaks the attribute.

## SHIPPED: `TranslateResponse` middleware (the "covers everything" deliverable)

`src/Http/Middleware/TranslateResponse.php`, alias `langsys.translate-page`,
config block `translate_response`, 17 tests in `tests/TranslateResponseTest.php`.

Wraps `Client::translatePage()` (+ `HtmlParser`, `SelectorMatcher`,
`MarkupTokenizer`, the translatable-attributes config) — the **server-side
analog of the JS SDK's DOM tokenizer** — as an opt-in response middleware over
the final rendered HTML, so the "covers ALL server-rendered Laravel (Blade,
Livewire, Alpine)" claim is literally true without hand-tagging: every text node
and translatable attribute gets translated, and write-key token discovery runs
over the whole page.

How the design landed:

- **Opt-in, scoped.** Only `text/html`; JSON, redirects, streamed and file
  responses pass through. Registered as an alias only — never added to a group,
  because automatic mode must not switch itself on for a project that tags with
  `@t`. `only`/`except` path patterns narrow it further; `except` wins.
  The content-type guard is what excludes Livewire and Inertia XHR round-trips
  without the middleware knowing those libraries exist.
- **Respect `translate="no"`** and the SDK's translatable-attributes config
  (already supported by `translatePage`).
- **Double-translation — RESOLVED: one mode per project.** Automatic *or*
  tagged, never both. The hazard is confirmed upstream by repro: a node `@t`
  already translated gets re-walked by `translatePage()`, looked up as a source
  phrase, missed, and **registered** — so a Spanish `"Guardar"` enters the
  catalog every Langsys SDK shares, as if it were a source phrase.
  `<p>Save</p><p>Guardar</p>` registers `["T", "Save", "Guardar"]`.

  A marker attribute was considered and rejected. `@t` emits escaped inline
  text and is used **inside attribute values** (`<input placeholder="@t('Your
  name', 'Forms')">`), where there is no element to mark; `translate="no"` on
  the `<input>` would exclude attributes we *do* want translated. A skip
  mechanism that covers text nodes but silently misses attribute positions is
  worse than none — it works until someone translates a placeholder.
- **`data-notrans` — RESOLVED in v1.2.0, now documented and tested.** It was
  inverted through v1.1.0: the raw attribute string was tested for PHP
  truthiness, so bare `data-notrans` was falsy. Measured here against v1.1.0:

  ```
  data-notrans          -> LEAKED — registered into the shared catalog
  data-notrans="true"   -> excluded
  data-notrans="false"  -> excluded   (the opt-out value also opts you in)
  ```

  Every explicit value excluded, including the one meaning "don't" — there was
  no string an author could write to opt back in, so the marker was unusable in
  both directions. This package documented only `translate="no"` throughout, so
  no user was ever told to write it; picking the standards-based marker over the
  vendor-specific one avoided the bug by construction. v1.2.0 makes presence
  intent with only `"false"`/`"0"` opting out, trimmed and case-insensitive.
  Ten cases now pinned in `TranslateResponseSafetyTest`, asserted on the
  registration list rather than rendered output — which is the layer where this
  class of bug surfaces first, and the reason it went unnoticed upstream while
  fragment tests checking rendered HTML stayed green.
- **`translate="no"` is the per-subtree escape hatch.** Already honoured by
  `HtmlParser`, `PageTranslator` and `MarkupTokenizer`, and already the
  cross-SDK answer — the JS tokenizer checks it on the line immediately above
  its own marker check. Standards-based HTML, no new vocabulary to keep in sync.
  Upstream repro: `<p translate="no">Guardar</p>` is not extracted, not
  registered, not rendered over. **Do not invent a wrapper-side skip marker** —
  the capability exists under a name the platform already gave us.
- **Caching ships disabled**, keyed by `(locale, sha1(source HTML))` — never by
  route, so a page varying by user or state can't serve another request's
  translation. That keying also makes it useless for most Laravel pages: a CSRF
  token or timestamp changes the hash every render. Only worth enabling for
  genuinely static, high-traffic HTML. Keep it dumb; it is the one piece of this
  middleware with room to grow teeth.
- **Inline `<script>` / `<style>` — VERIFIED, `tests/TranslateResponseSafetyTest.php`.**
  A response middleware runs over *whole rendered responses*, which is exactly
  where bootstrapped JSON state, CSRF tokens and inline JS live. Upstream found
  and fixed (pre-release) a `data-langsys-phrase` bug that encoded script bodies
  into registered phrases — a catalog entry could then rewrite inline JS back
  into the page, a stored-XSS shape. That test runs the **real** page translator
  (only `getTranslations()`/`canWrite()` stubbed, to stay off the network) over a
  Laravel-shaped page and asserts script and style bodies survive byte-for-byte,
  both CSRF token copies are intact, nothing script-ish is registered, and
  `translate="no"` is honoured — while confirming an untranslated heading *is*
  registered, so the guard can't pass vacuously. Keep that test honest if the
  page fixture changes.

### Closed: page registration bypassed the flush middleware (fixed in v1.3.0)

Reported from here, fixed upstream, adopted. `translatePage()` now queues
through `queuePhraseForRegistration()` / `queueContentBlockForRegistration()`
like `translate()` does, so `FlushPendingRegistrations` and the Octane listener
drain page registration too — the after-the-response guarantee covers both
modes. Upstream measured a page with four new blocks and four new phrases going
from 5 POSTs + 1 GET during render to **zero**, with 2 batched POSTs at flush.

Kept for the reasoning, since the same shape could recur:

<details>
<summary>What it was, and why it wasn't a constraint</summary>

`translatePage()` does **not** use the pending-registration queue that
`translate()` uses. It calls `Client::canWrite()` and then
`Client::registerPhrases()` **inline**, mid-render, both of which are HTTP:

```
PageTranslator::registerNewItemsWithCategory()
  -> $this->client->canWrite()          // 1. HTTP permissions check
  -> $this->client->registerPhrases()   // 2. HTTP POST
PageTranslator::translateDocument()
  -> $this->client->clearCache($locale)
  -> $this->client->getTranslations($locale)   // 3. HTTP refetch
```

So `FlushPendingRegistrations` and the Octane listener **do not govern automatic
mode**. On a write key, a page containing new phrases makes **three** blocking
HTTP calls before the response is sent — the opposite of the
after-the-response guarantee tagged mode gives. All are wrapped in silent
`catch`, so a failure costs latency rather than correctness, and read-only keys
skip the whole path.

Upstream's read (they traced it rather than recalled it): registration is inline
*so that* the third call can apply the refetched catalog within the same
response — but the items just registered are brand new and have no translations
yet, so the refetch can only surface work a translator completed after some
earlier request, which the next page load would pick up anyway. Not a
constraint, a coupling: `translate()`'s queue came first and `translatePage()`
was written to register directly. Content blocks don't force it either —
`custom_id` is computed locally, so nothing needs a round-trip before the
response finishes.

Consequence while it lasts: **automatic mode with a write key is a
development-only configuration**, more strongly than for tagged mode.

It was worse than first measured: content blocks registered one POST *each* in
a loop, so an eight-item page was ten round trips, not two.

</details>

**Lesson kept from the upgrade — a moved capture point fails asymmetrically.**
`TranslateResponseSafetyTest` used to hook `registerPhrases()`. After v1.3.0
nothing calls it, so that hook recorded nothing: the `assertContains` cases
failed loudly, but every `assertNotContains` exclusion case would have **passed
vacuously**, "proving" exclusion against a recorder that recorded nothing.
Chasing the red to green would have left the silent half broken.

The test now reads `Client::getPendingPhrases()` directly instead of overriding
a method — the actual boundary deciding what reaches the catalog, and one that
breaks loudly rather than quietly if it moves again. Every absence assertion is
paired with a positive control (an unmarked subtree MUST be queued) proving the
capture point is live. Apply that pairing to any new exclusion test.

**Also:** the SDK's `register_shutdown_function` flushes whatever is still
queued at process exit. Tests that deliberately leave phrases queued must stub
`flushPendingRegistrations()`, or the suite POSTs them for real — harmless
against a fake key, but it would write test fixtures into a live shared catalog
if a developer had real credentials in their environment.

- **Same-response self-healing is gone**, by design: a page no longer picks up
  translations an earlier request registered within the same response. They
  appear on the next one. Nothing here relied on it.
- **`data-langsys-phrase` is a `translatePage()`-only feature.** A marked run
  still splits inside a content block. That asymmetry should drive scoping: the
  keep-together primitive is an argument for the response-middleware path over
  content blocks for markup-bearing copy.
- **Requires `langsys/langsys-php` ^1.3** — each earlier version is unusable
  here for a different reason, which is worth knowing before anyone relaxes the
  constraint: v1.0.0 never interpolated the `<head>` (so `<title>` and
  `og:*`/`twitter:*` shipped raw `{name}` while the body looked correct);
  through v1.2.0 page registration blocked the response with inline HTTP; and
  through v1.1.0 `data-notrans` excluded nothing. Every one of those fails
  quietly.

**The boundary that keeps this a wrapper.** The middleware may decide **whether**
to call `translatePage()` and **what to hand it**. It must never decide **what
inside the HTML** gets translated — which elements are walked, which attributes
are translatable, what `translate="no"` means, how markup is tokenized. Those
are upstream's, permanently. The moment wrapper code inspects the HTML itself it
has stopped being a wrapper; that is how the duplicate `Interpolator` happened.
Caching translated output (route + locale + content hash) is legitimately ours,
and is the one piece with room to grow teeth — keep it dumb.

Status: **not started, unblocked, design settled.** Everything needed on the
PHP-SDK side exists as of v1.1.0, and upstream has confirmed `translatePage($html,
$category, $selectorCategories, $params)` is stable — the v1.1 markup work changes
extraction underneath, not the signature. This is purely wrapper work.

## Closed

- ~~Livewire support was architected but not test-proven.~~ Done — commit
  `fbcda15`, `tests/LivewireSupportTest.php` (39-test suite).

## Open bug upstream: missing ICU select argument destroys the sentence

**Do not delete `refactor/827_gender_context_translation`.** It is the only
record of this analysis, and it is not a stale branch — it carries an unmerged
fix by giancapra (2026-08-04) against `src/Interpolator.php`, a file deleted
here when interpolation was consolidated into `langsys/langsys-php`. The fix
therefore went nowhere, and the bug it fixed is live in upstream v1.3.0, which
this package now delegates to. Verified against the installed version:

```php
$tpl = '{name_gender, select, male {Bienvenido} female {Bienvenida} other {Bienvenide}} {name}';
interpolate($tpl, ['name' => 'Sarah', 'name_gender' => 'female'], 'es-ES');  // 'Bienvenida Sarah'
interpolate($tpl, ['name' => 'Sarah'], 'es-ES');                             // '{name_gender} Sarah'  <-- sentence gone
interpolate('{count, plural, one {# item} other {# items}}', [], 'es-ES');   // '{count}'
```

**Reachable without caller error.** The ICU promoter in langsys-ai introduces a
select argument the source phrase never carried — `{name}` becomes
`{name_gender, select, …}` in gendered target locales — so an app cannot supply
a value it has no way to know about. Any app translating into a gendered locale
hits it.

**Why upstream's malformed-ICU fallback doesn't catch it:** `MessageFormatter`
neither throws nor returns `false`; it returns a bare `{name_gender}`, which
reads as success, so the ICU path never falls through to simple substitution. A
*well-formed* pattern with a missing argument bypasses the guard built for
malformed ones — which is why every test that supplies complete params passes.

Reported upstream (mesh topic `icu-missing-select-argument`). **The fix belongs
there, not here** — do not reintroduce a wrapper-side Interpolator to work
around it. No failing test is parked in this suite.

**FIXED AND RELEASED in `langsys/langsys-php` v1.3.1** — adopted and verified
here against the real interpolator, in both intl modes:

```
                      WITH intl            WITHOUT intl (php -n)
select complete    -> 'Bienvenida Sarah'   'Bienvenida Sarah'
select MISSING     -> 'Bienvenide Sarah'   'Bienvenide Sarah'    (was '{name_gender} Sarah')
select NULL arg    -> 'Bienvenide Sarah'   'Bienvenide Sarah'
plural complete    -> '1 item'             '1 item'
plural MISSING     -> '{count} items'      '{count} items'       (was '{count}')
```

A missing argument selects `other`, which every `plural` and `select` must
provide. **The asymmetry is deliberate and must not be "fixed":** for `select`,
`other` is genuinely the right branch for an unknown gender, so the sentence is
*correct*; for `plural` nothing can infer a count, so `{count} items` is merely
*less bad* than a destroyed sentence or a dumped pattern. Upstream defends this
in `testMissingPluralArgumentKeepsTheSentenceAndShowsTheGap` and in a comment at
the code, so anyone reading `{count}` as a bug meets the reasoning first.

The two intl modes now agree byte-for-byte, including on the incomplete-param
cases that previously produced two different broken outputs from one input.

`refactor/827_gender_context_translation` was deleted once this shipped, having
served its purpose. Its single commit is **`229732fbd75eb9805aadaa64083349cb3c152d62`**
(giancapra, 2026-08-04) — recoverable by SHA if the original patch is ever
wanted, though it targets the deleted wrapper-side `Interpolator` and is
superseded by upstream's fix.

Two corrections worth keeping, both from upstream checking rather than assuming:

- **giancapra's commit message says the base JS SDK already carries this guard.
  It does not.** Upstream read `interpolate.ts` and verified against the
  published 0.6.3 tarball: `intl-messageformat` *throws* where PHP's
  `MessageFormatter` echoes, so JS falls through and emits the **entire raw ICU
  pattern**. Both SDKs were broken, differently, and there was no parity
  reference to copy. Relevant here because this package hands the same catalog
  to the JS SDKs through `InertiaSsrProps` — a gendered phrase rendered
  client-side has its own version of this bug until the JS side is fixed too.
- **The fix also removed an `ext-intl` divergence.** Behaviour previously
  differed with and without the extension (intl echoed a bare `{arg}`, no-intl
  left the whole pattern), so one input had two different broken outputs on the
  same SDK. They now agree.

## Cross-SDK notes

- `%name%` markup placeholders (base JS SDK `langsys-js-typescript` ^0.4.1) are a
  JS-framework-compiler concern and **do not apply to Blade** (single `{name}` is
  literal in Blade). Interpolation now lives in `langsys/langsys-php` and matches
  the canonical `{name}` form, so Laravel output stays consistent with the JS SDKs
  by construction rather than by our keeping a port in sync.
- **SSR-handoff marker mismatch — FIXED cross-SDK, but it dictates a version
  pairing.** PHP's author-facing `data-langsys-phrase` survives into
  `translatePage()` output, while the JS tokenizer originally skipped only its
  own internal `data-ls-phrase`. A page rendered by `translatePage()` and then
  hydrated by a JS SDK had the JS side walk into a subtree PHP had already
  tokenized.

  **The damage was worse than a wasteful re-walk.** The JS tokenizer recurses
  *per text node*, so it didn't re-register the phrase — it **split the run at
  tag boundaries**:

  ```
  <p data-langsys-phrase>Read the <a href="/d">docs</a> now</p>
    -> registers THREE fragments: ["Read the", "docs", "now"]
  ```

  That is exactly the fragmentation the keep-together primitive exists to
  prevent — a count and the noun it inflects land in separate catalog entries
  where no ICU plural can reach across them. The sentence goes in whole from PHP
  and comes back in pieces from JS, into the same catalog.

  Fixed in `langsys-js-typescript`: `PHRASE_MARKER_ATTRS = ['data-ls-phrase',
  'data-langsys-phrase']` with a shared `isPhraseMarked()` used at both skip
  sites (`translate.ts:222`, `content-block.ts:246`) and exported from the
  package index — verified in the sibling checkout. Wrappers that tokenize
  through their own templating can import it rather than re-deriving the list.

  **Live constraint for `TranslateResponse`:** a Laravel app doing PHP SSR plus
  JS hydration is the deployment shape where this arises, and it is the shape
  this middleware is most likely to run in — the middleware would make the
  combination routine rather than exotic. So when it ships, its docs must state
  the version pairing: server-rendering with `translatePage()` requires a JS SDK
  whose tokenizer recognises `data-langsys-phrase`. Older JS versions fragment
  the catalog silently.

  Why it slipped through: the two attribute names were allowed to diverge
  because one is author-facing and the other internal — sound reasoning, right
  up until SSR puts both in **one DOM walked by both implementations**.
- **Inline-markup tokenization lands upstream in v1.1.** `MarkupTokenizer`
  encodes inline markup as `{m<i>o}`/`{m<i>c}` tokens, wire-format-identical to
  the JS SDK's `<Phrase>` component, so a subtree marked `data-langsys-phrase`
  registers as ONE phrase instead of splitting the count away from the noun it
  inflects. Upstream has confirmed **`translatePage($html, $category,
  $selectorCategories, $params)` does not change again** — it's driven by an
  HTML attribute, not a new argument — so the `TranslateResponse` middleware
  below is safe to build against the current signature; extraction just gets
  better underneath. They'll notify before it lands.

## Closed (v1.0.0 migration)

- ~~Our `Interpolator` is a hand-port of the JS `interpolate()`.~~ Deleted.
  Upstream v1.0.0 ships its own, verified against the JS SDK's output; we pass
  `$params` through instead. This also fixed catalog pollution we couldn't:
  registration queues the raw placeholder phrase.
- ~~`DetectLocale` parses `Accept-Language` itself.~~ Delegated to
  `LocaleDetector::fromBrowser()`, which fixed a q-value/ext-intl inconsistency
  our copy had reproduced.
