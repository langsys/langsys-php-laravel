# Roadmap / known gaps

Running list of deferred work and design decisions for `langsys/laravel-sdk`, so
the context isn't lost between sessions.

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
- **Double-translation risk.** If both `@t` tagging and the response middleware
  run, an already-`@t`-translated node gets re-walked by `translatePage`, looked
  up as a phrase (miss), and registered as garbage. Decide the model: either
  the middleware is used *instead of* `@t` (automatic mode), or it must skip
  nodes already resolved by `@t` (e.g. a marker attribute). Cleanest is
  "pick one mode per project."
- **Performance.** Parsing every HTML response has a cost; consider caching the
  translated output keyed by (route, locale, content hash), and only walking
  when translations for the locale exist.
- **Config.** Add a `translate_response` block (enabled, only-these-paths,
  except-these-paths, cache).

- **Requires `langsys/langsys-php` ^1.0.1 specifically.** In v1.0.0
  `translatePage()` never interpolated the `<head>`, so `<title>`, meta
  description and `og:*`/`twitter:*` shipped raw `{name}` to the browser while
  the body resolved correctly. Fixed in v1.0.1 — but it means any prototype of
  this middleware built against v1.0.0 would have looked correct in-page while
  silently corrupting social/SEO metadata.

Status: **not started.** Everything needed on the PHP-SDK side already exists;
this is purely wrapper work.

## Closed

- ~~Livewire support was architected but not test-proven.~~ Done — commit
  `fbcda15`, `tests/LivewireSupportTest.php` (39-test suite).

## Cross-SDK notes

- `%name%` markup placeholders (base JS SDK `langsys-js-typescript` ^0.4.1) are a
  JS-framework-compiler concern and **do not apply to Blade** (single `{name}` is
  literal in Blade). Interpolation now lives in `langsys/langsys-php` and matches
  the canonical `{name}` form, so Laravel output stays consistent with the JS SDKs
  by construction rather than by our keeping a port in sync.
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
