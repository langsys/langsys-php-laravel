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

Status: **not started.** Everything needed on the PHP-SDK side already exists;
this is purely wrapper work.

## Closed

- ~~Livewire support was architected but not test-proven.~~ Done — commit
  `fbcda15`, `tests/LivewireSupportTest.php` (39-test suite).

## Cross-SDK notes

- `%name%` markup placeholders (base JS SDK `langsys-js-typescript` ^0.4.1) are a
  JS-framework-compiler concern and **do not apply to Blade** (single `{name}` is
  literal in Blade). Our `Interpolator` matches the canonical `{name}` form, so
  Laravel output stays consistent with the JS SDKs. The one latent upstream gap:
  `langsys/php-sdk`'s `translatePage()` has no `%name%` normalization equivalent —
  a `langsys-php` concern, relevant only if the `TranslateResponse` middleware
  above is built and someone authors `%name%` in server-rendered markup.
