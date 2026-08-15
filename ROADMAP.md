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

## Proposed: `TranslateResponse` middleware (the "covers everything" deliverable)

The vanilla PHP SDK already ships `Client::translatePage($html)` (+ `HtmlParser`,
`SelectorMatcher`, the translatable-attributes config) — the **server-side
analog of the JS SDK's DOM tokenizer**. Wire it as an opt-in response middleware
that runs over the final rendered HTML, and the "covers ALL server-rendered
Laravel (Blade, Livewire, Alpine)" claim becomes literally true without
hand-tagging: every text node and translatable attribute (`placeholder`,
`alt`, `aria-label`, etc.) gets translated, and write-key token discovery runs
over the whole page.

Design considerations to resolve when building it:

- **Opt-in, scoped.** Only translate `text/html` responses; skip JSON,
  redirects, downloads, streamed responses. Likely a `langsys.translate-page`
  route/group middleware, not global-by-default.
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
- **Performance.** Parsing every HTML response has a cost; consider caching the
  translated output keyed by (route, locale, content hash), and only walking
  when translations for the locale exist.
- **Config.** Add a `translate_response` block (enabled, only-these-paths,
  except-these-paths, cache).

- **Inline `<script>` / `<style>` are the sharp edge here.** A response
  middleware runs over *whole rendered responses*, which is exactly where
  bootstrapped JSON state, CSRF tokens and inline JS live. Upstream found and
  fixed (pre-release, never shipped) a `data-langsys-phrase` bug that encoded
  script bodies into the registered phrase — meaning a catalog entry could
  rewrite inline JS back into the page. v1.1.0 preserves opaque subtrees and
  `translate="no"` verbatim. **Verify that directly against a real Laravel
  response before enabling this middleware anywhere**, because the wrapper is
  what would feed it whole pages; a unit test over a fragment won't exercise it.
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
- **Open cross-SDK interop gap: the SSR-handoff marker mismatch.** PHP's
  author-facing `data-langsys-phrase` **survives into `translatePage()` output**
  (verified upstream), while the JS tokenizer skips only its own internal
  `data-ls-phrase` (`PHRASE_MARKER_ATTR`, `langsys-js-typescript/src/phrase.ts`).
  So a page rendered by `translatePage()` and then hydrated by a JS SDK whose
  tokenizer walks the live DOM has the JS side recurse into a subtree PHP already
  tokenized. The two attributes were allowed to diverge because one is
  author-facing and the other internal — true until SSR puts both in **one DOM
  walked by both implementations**. Upstream is raising it with the base-SDK
  agent; likely fix is the JS tokenizer also skipping `data-langsys-phrase` (one
  line, no break) rather than renaming a published PHP attribute. **Scope
  honestly: reachable, not observed** — no deployment is confirmed doing PHP
  `translatePage()` + JS-tokenizer hydration over the same markup, and a JS app
  rendering its own components from the catalog never sees PHP's DOM. Cheap and
  silent, not on fire. Recheck before building `TranslateResponse`, since that
  middleware is what would make the combination routine.
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
