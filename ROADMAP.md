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

### Found while building: page registration bypasses the flush middleware

`translatePage()` does **not** use the pending-registration queue that
`translate()` uses. It calls `Client::canWrite()` and then
`Client::registerPhrases()` **inline**, mid-render, both of which are HTTP:

```
PageTranslator::registerNewItemsWithCategory()
  -> $this->client->canWrite()          // HTTP permissions check
  -> $this->client->registerPhrases()   // HTTP POST
```

So `FlushPendingRegistrations` and the Octane listener **do not govern automatic
mode**. On a write key, a page containing new phrases makes blocking HTTP calls
before the response is sent — the opposite of the after-the-response guarantee
tagged mode gives. Both calls are wrapped in silent `catch`, so a failure costs
latency rather than correctness, and read-only keys skip it entirely.

Consequence: **automatic mode with a write key is a development-only
configuration**, more strongly than for tagged mode. Raised upstream — the ask
is whether `translatePage()` could queue through the same pending mechanism so
the terminable middleware drains it after the response.
- **`data-langsys-phrase` is a `translatePage()`-only feature.** A marked run
  still splits inside a content block. That asymmetry should drive scoping: the
  keep-together primitive is an argument for the response-middleware path over
  content blocks for markup-bearing copy.
- **Requires `langsys/langsys-php` ^1.0.1 at minimum, ^1.1.0 in practice.** In
  v1.0.0 `translatePage()` never interpolated the `<head>`, so `<title>`, meta
  description and `og:*`/`twitter:*` shipped raw `{name}` to the browser while
  the body resolved correctly. Fixed in v1.0.1 — but it means any prototype of
  this middleware built against v1.0.0 would have looked correct in-page while
  silently corrupting social/SEO metadata.

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
