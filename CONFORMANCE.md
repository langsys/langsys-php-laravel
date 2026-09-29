# Conformance — langsys/langsys-php-laravel

| **Spec revision read** | langsys2 234eab14cd8787ad9b4a8c51287590865adff69a, docs/sdk-spec.mdx blob 7eee2c10398a1032831837c310215f3b9f16d306 |
|---|---|
| **Profiles** | server, binding, all — derived: binding over langsys-php-sdk |
| **Spec version** | 8.2.20, committed at `234eab14cd8787ad9b4a8c51287590865adff69a` and unpublished |
| **Core bound against** | langsys-php-sdk `544b24f` (`v1.3.1-80`, `feature/838_write_key_gating_reland`, untagged), whose conformance file is canonical — see *Release gate* |
| **Derived** | 2026-09-27T00:52:54Z by `git rev-parse` on the commit above; the text graded is byte-identical to the text read |

**The profile is derived.** The spec's rules name Laravel as an example throughout, but no profile line names a PHP framework binding, so this package takes its core's profiles (`server`, `all`) and adds `binding`.

Every rule id in the spec is rowed exactly once, including the ones that do not bind this package. A row graded `implemented` names a test that fails when the behaviour is removed; the mutation that proves it is in *Mutation record*. `delegated` names the core row it rests on and the absence probe that shows this package does not do the work itself, with a firing control that shows the probe can fail. `provisional` rows rest on evidence that stands in for the API's answer, and wait on this package vendoring the shared contract fixture. Counts live only in *Computed summary*, which is produced by a script, not typed.

**A `delegated` row is only as good as the core row it cites, and it is graded that way.** Its tier is `-`, because the behaviour's tier lives on the core row, and the summary resolves each delegated row against the core's current grade, as the fleet checker does. At `544b24f` one core row is `not implemented`, MIG-9, so it counts red here.

**Three changes graded here are pending the operator's ruling:** the removal of `auto_flush`, the removal of the `TranslateResponse` page cache, and the lowercase Inertia locale. The rows grade the code at this tip, and each row that rests on one of them says so. *Pending the operator's ruling* lists what each row becomes if a change is reverted.

Test paths are under `tests/`. In an evidence cell, `::name` continues the last file named in that cell. `M`, `T`, `F`, `P`, `L`, `C`, `V`, `S`, `K`, `R`, `A`, `E` and `N` numbers refer to *Mutation record*.

## What surfaced while writing this

Found by executing code against the rules — a probe, a real-core test or a mutant.

- **Laravel's `setLocale()` rewrites `app.locale`.** So "the locale is still `app.locale`" cannot tell whether the application resolved the locale: after any `setLocale()` the two are equal. SRV-6's "nothing resolved it" is Laravel's `LocaleUpdated` event having fired this request, which also counts an application that sets its default explicitly.
- **The test double for the core was pointed at the production API.** `FakeClient` kept the core's default base URL, so a test that reached past the seeded catalog — the locale middleware reading authorization — would have called api.langsys.dev with a test key. It now points at a closed local port.
- **Laravel fills `required_if`'s `:value` from the request data, not from the rule.** A listing built from the rule alone writes "when type is empty" into the sentence. `FormRequestSource` seeds the other field with the rule's value, which is the data whenever the rule fails.
- **A wildcard field with no label is a phrase per index.** Laravel writes the concrete path (`lines.0.qty`) into the sentence, so every index registers a new template. The listing names it, with the fix: a label in `attributes()`.
- **A rule object fails under its class, with its own text.** `Password` and every application rule object register at first send and cannot be listed ahead of time; the listing names each one.

## Status

| Rule | Status | Tier | Evidence |
|---|---|---|---|
| GATE-1 | delegated | - | Core `GATE-1`: implemented. The binding never reads `write_enabled` or `key_type` and never calls `canWrite()` on any render path: `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`, firing control `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`, M16a. The listing command's `--register` reaches `canWrite()` only through the core's `MessageCatalogCommand::register()`. |
| GATE-2 | n/a (architecture: synchronous core — the write decision resolves in-line at the send site, so no unknown window exists to hold a phrase through; live if the core became asynchronous) | - | The spec records synchronous SDKs `n/a` here. The residual obligation is REG-10, delegated below. |
| GATE-3 | implemented | n/a (pure) | Octane workers and queue workers keep the `Client` singleton across units of work, so the provider flushes and then calls `resetRequestState()` at every boundary: queue job processed, queue job threw, and Octane `RequestTerminated`. `RequestScopeTest::testTheNextUnitOfWorkStartsWithoutAWriteDecision` sets a decision, crosses each boundary and asserts the next unit starts without one. `::testEveryBoundaryFlushesAndThenResets` pins the flush before the reset, and `::testABoundaryNeverBuildsAClientNobodyUsed` covers an unused client. M1, M2, M3, M4, M5. The Octane leg dispatches a stand-in `Laravel\Octane\Events\RequestTerminated`: Octane is not installed, and the provider listens by class name. Keeping the decision out of any shared cache is the core's half, core `GATE-3`: implemented. PHP-FPM needs no reset, because the process ends the scope. |
| GATE-4 | delegated | - | Core `GATE-4`: implemented. The binding writes no cache entry of its own — `TranslateResponseTest::testEveryRequestReachesTheSdk`, M10 (the page cache's removal is pending the operator's ruling) — and `LaravelCacheAdapter` stores exactly what the core hands it, `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. It never names the flag it would have to strip or add: capability scan, M16a. |
| GATE-5 | delegated | - | Core `GATE-5`: implemented. The binding keeps no registration bookkeeping and never reads a flush result; `flushPendingRegistrations()` is its only call on the write lane. `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `queuePhraseForRegistration`, `createPhrases`, `registerPhrases` and `clearPendingRegistrations`; firing control `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`; M16c. |
| GATE-6 | delegated | - | Core `GATE-6`: implemented. The binding has no hint lane: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `discovery/hint` and all transport. It makes no write decision either, `::testTheBindingNeverTouchesServerComputedCapability`. |
| GATE-7 | delegated | - | Core `GATE-7`: implemented. Every Laravel route that can meet unregistered content hands it to a core entry point that feeds the register lane: `t()`, `@t` and migrate-mode `__()` go to `translate()`, `TranslateResponse` goes to `translatePage()`, a validation failure goes to `emitMessage()`, and Livewire and queued jobs go through `t()`. Pass-through probes: `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` and `TranslateResponseTest::testServesExactlyWhatTheSdkReturned`. `ServedBytesTest::testTheServedBytesCarryTheRequestLocalesTranslations` observes the miss the real core queued. |
| GATE-8 | delegated | - | Core `GATE-8`: implemented. The binding consults neither the flag nor the key type: `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`, M16a. |
| GATE-9 | n/a (profile: browser) | - | The discovery gate governs a client's `t()`. This package runs on the server and sends no discovery. |
| GATE-10 | not implemented | - | **Producing is the half that binds a server, and this package meets it on one route of two.** A page `TranslateResponse` translates goes through `translatePage()`, which marks its root `data-ls-resolved` when the render locale is not the base locale (core `GATE-10`: implemented). Text a Blade view prints translated inline — `@t`, `t()`, migrate-mode `__()` — carries no marker, so a JS SDK on that page reads it as source and can register translated text. The fix is an opt-in Blade directive an app puts on its layout root, and it waits on the operator's ruling (*Pending the operator's ruling*). Gap 1. Reading is a DOM host's, and this package has none. |
| CAT-1 | delegated | - | Core `CAT-1`: implemented. The binding never indexes the catalog. The one place it holds one keeps present-with-null present: `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. `InertiaSsrProps` hands `getTranslations()` through unmodified: `InertiaSsrPropsTest::testHandsTheClientTheCatalogThisRequestRenderedWith`. |
| CAT-2 | delegated | - | Core `CAT-2`: implemented. `LangsysTranslator` returns what the core returns: `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` hands back a result no fallback could produce, M7a. `::testDoesNotSwallowAFailureTheSdkLetThrough` covers a failure, M7b. The identity scan `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `Interpolator` and `getInterpolator`. |
| CAT-3 | delegated | - | Core `CAT-3`: implemented. The binding never resolves a content block: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `translateContentBlock`, `HtmlParser` and `PageTranslator`. `TranslateResponse` hands over the response bytes untouched, `TranslateResponseTest::testTranslatesAnHtmlResponse`. |
| REG-1 | delegated | - | Core `REG-1`: implemented; the core drops the queue without a request when this request may not write. The binding makes no write decision, `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`. `terminate()` and the boundary listener call `flushPendingRegistrations()` without a guard of their own. |
| REG-2 | n/a (architecture: request-scoped flush — the misses of a request or a job leave together when it ends, so there is no stream of sends to debounce; live if this package flushed on a timer, for instance inside a daemon loop) | - | One flush per unit of work: `FlushPendingRegistrationsTest::testDiscoveredPhrasesAreFlushedAfterTheResponse` and `RequestScopeTest::testEveryBoundaryFlushesAndThenResets`. No timers: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `sleep` and `usleep`. |
| REG-3 | implemented | n/a (pure) | **The automatic path is this package's, on every way a Laravel execution context ends.** PHP-FPM: `terminate()` flushes after the response, `FlushPendingRegistrationsTest::testDiscoveredPhrasesAreFlushedAfterTheResponse`, and `::testTheFlushRunsOnlyOnceTheResponseExists` pins the order of events, M8. Octane request, and queue job finished or thrown: `RequestScopeTest::testEveryBoundaryFlushesAndThenResets`, M1, M4, M5. The public manual flush is the core's, reachable as `Langsys::client()->flushPendingRegistrations()`, `FacadeTest::testClientReturnsTheContainersSdkClient`. Logging a failed flush is the core's, core `REG-3`: implemented. |
| REG-4 | n/a (profile: browser) | - | A server binding has no page teardown. |
| REG-5 | n/a (profile: browser) | - | A server binding has no page teardown. |
| REG-6 | n/a (architecture: synchronous flush — the core's send runs to completion before anything else in the request can queue, so nothing joins the live queue mid-send; live under a coroutine runtime such as Swoole with coroutine hooks) | - | The binding never touches the queue: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| REG-7 | n/a (architecture: synchronous flush — one send completes before the next statement runs, so two cannot be in flight; live under the same coroutine runtime as REG-6) | - | As REG-6. |
| REG-8 | delegated | - | Core `REG-8`: implemented; the backoff clock lives on the `Client` singleton and carries across Octane requests, and `resetRequestState()` drops what is still queued. The binding flushes before it resets, so a unit's phrases are sent, or dropped with the unit, never carried into the next: `RequestScopeTest::testAFlushThatCannotReachTheApiFailsNothing` asserts an empty queue after a failed flush and the reset, M1, M2. The binding adds no retry or timer: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`, M16c. |
| REG-9 | delegated | - | Core `REG-9`: implemented. The binding does no batching and constructs no request: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `createPhrases`, `createContentBlocks` and transport. |
| REG-10 | delegated | - | Core `REG-10`: implemented; a skipped write returns a non-success result naming its reason. The binding reshapes none of it: at a boundary, a write skipped for a read key comes back from the core as `success: false` with `reason: not_write_enabled`, and the binding's call site returns nothing, `RequestScopeTest::testASkippedWriteAtABoundaryIsNamedByTheCore`. A boundary and `terminate()` have no caller to hand a result to, so the reason reaches the core's log, not a return value. The binding never throws into a render from the write lane, `::testAFlushThatCannotReachTheApiFailsNothing`, and swallows nothing the core lets through: `LangsysTranslatorTest::testDoesNotSwallowAFailureTheSdkLetThrough` and `TranslateResponseTest::testDoesNotSwallowAFailureTheSdkLetThrough`, M7b, M9. |
| REG-11 | delegated | - | Core `REG-11`: implemented. The binding inspects no phrase text: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour`. |
| REG-12 | delegated | - | Core `REG-12`: implemented. The binding never inspects catalog structure: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour`, plus the pass-through probes under GATE-7. |
| REG-13 | delegated | - | Core `REG-13`: implemented. The binding decides nothing about registration; every miss is the core's, on a catalog the core loaded. With the API unreachable nothing is queued: `LangsysTranslatorTest::testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing`, M21. |
| HINT-1 | n/a (profile: browser) | - | A server binding has no report lane; HINT-2 governs. |
| HINT-2 | delegated | - | Core `HINT-2`: implemented. Server SDKs never report, and this package sends nothing at all: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `discovery/hint`, transport and request construction, M16c. |
| HINT-3 | n/a (profile: browser) | - | No page URL and no report lane in a server binding. |
| HINT-4 | n/a (profile: browser) | - | As HINT-3. |
| HINT-5 | n/a (profile: browser) | - | As HINT-3. |
| HINT-6 | n/a (profile: browser) | - | As HINT-3. |
| HINT-7 | n/a (profile: browser) | - | As HINT-3. |
| HINT-8 | n/a (profile: browser) | - | As HINT-3. |
| HINT-9 | n/a (profile: browser) | - | As HINT-3. |
| HINT-10 | n/a (profile: browser) | - | As HINT-3. |
| HINT-11 | n/a (profile: browser) | - | As HINT-3. |
| HINT-12 | n/a (profile: browser) | - | As HINT-3; its server mirror is the Langsys backend, not an SDK. |
| HINT-13 | n/a (architecture: every navigation in a Laravel app is a new request through the same middleware and the same core entry points, so there is no client-side route change to re-enter from; live if this package shipped client-side routing) | - | The JS framework bindings own route changes on an Inertia page. |
| ICU-1 | delegated | - | Core `ICU-1`: implemented. Interpolation is the core's. The binding hands `$params` over raw, `LangsysTranslatorTest::testPassesParamsThroughToTheSdkSoTheRawPhraseIsRegistered`, and applies nothing to the result, `::testReturnsExactlyWhatTheSdkReturned`, M7a. `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `Interpolator` and `MessageFormatter`. A `trans_choice()` count reaches the core's ICU as the `count` param, `Translation/MigrateTranslatorTest::testATranslatedPluralSelectsForTheRequestLocale`, T3. |
| ICU-2 | delegated | - | Core `ICU-2`: implemented. Same evidence as ICU-1. |
| ICU-3 | delegated | - | Core `ICU-3`: implemented. Same evidence as ICU-1. |
| ICU-4 | delegated | - | Core `ICU-4`: implemented; the notice goes through the core's logger. Same evidence as ICU-1. |
| ICU-5 | delegated | - | Core `ICU-5`: implemented. Same evidence as ICU-1. |
| ICU-6 | delegated | - | Core `ICU-6`: implemented. Same evidence as ICU-1. |
| CID-1 | delegated | - | Core `CID-1`: implemented. The binding derives no id: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `md5`, `sha1`, `hash`, `crc32` and `json_encode`, M16b. |
| CID-2 | delegated | - | Core `CID-2`: implemented. The binding never spells a category sentinel: an uncategorized call passes `null` and the core names it. The identity scan forbids the literal `__uncategorized__`, M7c, and the lookup still resolves, `LangsysTranslatorTest::testAnUncategorizedPhraseResolvesThroughTheSdksOwnNamespace`. |
| CID-3 | delegated | - | Core `CID-3`: implemented. The binding reads content blocks only through `translatePage()` and resolves none itself: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour`. |
| CID-4 | delegated | - | Core `CID-4`: implemented. As CID-3. |
| TOK-1 | delegated | - | Core `TOK-1`: implemented. The binding tokenizes nothing: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `DOMDocument`, `DOMXPath`, `HtmlParser`, `MarkupTokenizer` and `preg_replace`. Its two routes to the tokenizer are the core's: `translate()` for a phrase, and `translatePage()` for markup, handed the exact response bytes, `TranslateResponseTest::testTranslatesAnHtmlResponse`. `TranslateResponseSafetyTest` runs the real page translator over a Laravel-shaped page: script and style bodies survive byte for byte, and nothing from them is queued. |
| TOK-2 | delegated | - | Core `TOK-2`: implemented. Same evidence as TOK-1. |
| TOK-3 | delegated | - | Core `TOK-3`: implemented. Same evidence as TOK-1. |
| TOK-4 | delegated | - | Core `TOK-4`: implemented. Same evidence as TOK-1. |
| TOK-5 | delegated | - | Core `TOK-5`: implemented. Both spellings belong to the core's interpolator and capture path; the binding passes phrases and params raw, as in ICU-1. |
| TOK-6 | delegated | - | Core `TOK-6`: implemented. Same evidence as TOK-1. |
| MARK-1 | delegated | - | Core `MARK-1`: implemented. The binding stamps nothing: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `data-ls-` and `data-langsys-` in code. |
| MARK-2 | delegated | - | Core `MARK-2`: implemented. The binding reads no host marker: same scan as MARK-1. |
| MARK-3 | delegated | - | Core `MARK-3`: implemented. On this package's page route, the core's reading holds: a block stamped `data-ls-contentblock="abc123"` that `TranslateResponse` serves registers under `abc123` outside a resolved scope, `ContentBlockIdentityTest::testAStampedBlockOutsideAResolvedScopeRegistersUnderItsId`, and registers nothing under a resolved ancestor, `::testAStampedBlockInsideAResolvedScopeRegistersNothing`, on the real core. Blade stamps no identity host: `@t` prints a phrase, and the identity scan forbids `data-ls-` and `data-langsys-` in code, as MARK-1. |
| MARK-4 | delegated | - | Core `MARK-4`: implemented. Same scan as MARK-1. `TranslateResponseSafetyTest::testTranslateNoSubtreeIsLeftAlone` shows a marked subtree left alone on this package's page route. |
| SSR-1 | n/a (profile: browser) | - | Governs the browser SDK's strategy under server rendering. |
| SSR-2 | n/a (profile: browser) | - | As SSR-1. |
| SSR-3 | n/a (profile: browser) | - | As SSR-1. |
| SRV-1 | provisional | mock | Proven on the route an application renders — locale middleware, Blade, `@t` — with the real core. `ServedBytesTest::testTheServedBytesCarryTheRequestLocalesTranslations` finds `Prezzi` in the served bytes for `?locale=it-IT`. Its control is a phrase absent from the catalog, served in the base language and queued as a miss. M19. The catalog is seeded into the cache the core reads, standing in for the API's answer. Waits on: the shared contract fixture, vendored here to serve that catalog through the real request path. |
| SRV-2 | implemented | n/a (pure) | PHP-FPM builds a container per request; Octane and queue workers do not, so the provider resets the core's per-request state at each boundary. `RequestScopeTest::testTheNextUnitOfWorkReadsTheCatalogAfresh` reads a catalog, moves the shared cache on, crosses each of the three boundaries and asserts the next unit reads the new catalog; M1, M4 and M5 each redden it. The rule's interleave cannot occur in these runtimes: a PHP-FPM process and an Octane worker each serve one request at a time, so concurrent requests never share a `Client`, and the failure that exists is sequential reuse across a boundary, which GATE-3 names. It becomes live with in-worker concurrency sharing the singleton, such as Swoole coroutine hooks. The catalog memo is the core's, core `SRV-2`: implemented. |
| SRV-3 | provisional | mock | The flush runs only once the response exists, `FlushPendingRegistrationsTest::testTheFlushRunsOnlyOnceTheResponseExists`, with `RequestHandled` before the flush; M8 moving the flush onto the request path reddens it. At long-lived boundaries the flush runs after the unit of work, `RequestScopeTest::testEveryBoundaryFlushesAndThenResets`. A read-only key pushing nothing is the core's decision, core `REG-1`, and the binding adds no capability branch, `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`. Graded on that API-dependent half. Waits on: the shared contract fixture, vendored here for a read-only key with a write-key positive control on the same render. |
| SRV-4 | implemented | n/a (pure) | **This package holds the server's half, and names the two halves it does not.** Held: hand the client the catalog the server rendered with. `InertiaSsrProps::share()` reads the same `getTranslations()` memo that `t()` read. `InertiaSsrPropsTest::testHandsTheClientTheCatalogThisRequestRenderedWith` renders `@t` on the real core, moves the shared cache on, and still receives the rendered catalog; M14 re-reading the cache reddens it. The locale goes over in the form both SDKs identify it by, its casing pending the operator's ruling: `::testEveryHostSpellingHandsTheSdkForm`, M15. An outage hands no seed instead of a 500, `::testAnUnreachableApiHandsNoSeedInsteadOfThrowing`, M13. Not held: the synchronous seed, which is langsys-js-typescript's, and calling it before hydration, which belongs to the JS framework binding under Inertia. A page `TranslateResponse` translates is terminal HTML with no hand-off. |
| SRV-5 | delegated | - | Core `SRV-5`: implemented. Once per subtree, on this package's own render route: `ServedBytesTest::testAMissRenderedManyTimesIsQueuedOnce` renders one miss eight times across three nested Blade loops on the real core, and counts one registration. The deduplication is the core's queue. The binding cannot add a registration of its own: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `queuePhraseForRegistration`, M16c. Blade and Livewire render eagerly on the server, with no lazy child that resolves to a fallback. |
| SRV-6 | provisional | mock | **Laravel's locale is served.** Where anything set it this request — the provider marks the request on Laravel's `LocaleUpdated` — the core maps it for the client and nothing is varied: `DetectLocaleTest::testALocaleTheAppResolvedIsServedAsItIs`, `::testTheAppSettingItsDefaultLocaleCountsAsResolved`, and through the core's `resolveRequestLocale(['framework' => …])` a bare language goes to `default_locales` and an unsupported one to the base, `::testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `::testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase`; Laravel's own locale is left as the app set it. L1, L2, L3, L4. **Where nothing set it**, `DetectLocale` reads the app's `sources` in order (`::testTheSourcesAreAskedInTheConfiguredOrder`, L5), validates each candidate against the project's locales narrowed by `supported` (`::testTheSupportedListNarrowsTheProjectsLocales`, `::testAnUnsupportedCookieFallsThroughAndIsNotReset`, L6, L7), and adds the `Vary` its choice depended on (`::testTheCookieBeatsTheHeaderAndTheResponseVariesOnCookie`, `::testTheHeaderIsNegotiatedAndTheResponseVariesOnIt`, `::testWithNothingUsableTheBaseLocaleIsServed`, L8, L9, L10). The core never sends a raw `Vary` header: `ServiceProviderTest::testTheClientNeverSendsVaryItself`, L14. The served set is authorization's answer, stubbed here. Waits on: the shared contract fixture, vendored here to serve authorization's locales. |
| SRV-7 | n/a (architecture: the request scope is the browser core's seam for JS bindings that render on a server; this package wraps the PHP server core, whose per-request state lives on a Client reset at every boundary, GATE-3 and SRV-2; live if this package rendered through a browser core) | - | The binding builds no request state of its own: the core's `Client` holds the locale, the catalog memo and the queue, and `RequestScopeTest` pins their reset at every boundary. |
| MSG-1 | implemented | n/a (pure) | Laravel's error body is untouched and the entries sit beside it under `langsys.messages.response_key`: `Messages/ResponseEnvelopeTest::testTheJsonBodyKeepsLaravelsShapeAndCarriesTheEntriesBesideIt`, `::testTheKeyIsConfigurable`, `::testAnOrdinaryResponseIsUntouched`. The pieces' names are configurable, `::testThePieceNamesAreConfigurable`, E1. A redirecting form carries them in the session, `::testARedirectingFormCarriesTheEntriesInTheSession`. Keep mode attaches nothing: `Messages/KeepModeEnvelopeTest::testTheJsonBodyCarriesNothingOfOurs`, `::testARedirectFlashesNothingOfOurs`. The entry object is the core's, core `MSG-1`: implemented. |
| MSG-2 | implemented | n/a (pure) | An entry's code is Laravel's own rule name, whatever the field's type: `Messages/ValidatorMessagesTest::testTheCodeIsLaravelsRuleName`, C1. A rule object or closure carries the class Laravel records it under, `::testARuleObjectOrClosureCarriesTheClassLaravelRecords`, C2. A failure with no rule carries no code, `::testATextOnlyFailureCarriesNoCode`, V1. The sentences are Laravel's own, read from the installed framework's `validation.php`. |
| MSG-3 | implemented | n/a (pure) | A template is Laravel's sentence in the source language with the label written in and non-translatable values as `{name}` markers: `Messages/ValidatorMessagesTest::testLabelsAreWrittenInAndNoPlaceholderSurvives`, `::testValuesStayOutsideThePhraseAsMarkers`, `::testTheSentenceIsReadInTheSourceLanguageNotTheRequestLocale`; filling it reproduces Laravel's own message byte for byte, `::testAFilledTemplateReproducesLaravelsOwnMessage`. Which placeholder is a label, a written-in value or a marker is classified for every rule the installed Laravel ships: `Messages/RuleWordingTest::testEveryRuleLaravelShipsIsClassified`, `::testEveryPlaceholderInEachLineIsClassified`, `::testLabelsAreNeverMarkers`. |
| MSG-4 | delegated | - | Core `MSG-4`: implemented. Every entry is built with the core's `ServerMessage::make()`, which fills `message`; a number stays a number, `Messages/ValidatorMessagesTest::testValuesStayOutsideThePhraseAsMarkers`. |
| MSG-5 | n/a (architecture: this package sends entries and renders none — Laravel's message bag and 422 `errors` keep Laravel's source-language text, and a client SDK renders each entry; live when server-rendered pages print translated errors, which awaits the operator's ruling) | - | `Messages/MigrateModeTest::testTheServerNeverEmitsTranslatedText`. A Blade-only page shows validation errors in the source language: Gap 2. The helper a server render would use is the core's `Client::translateMessage()`. |
| MSG-6 | delegated | - | Core `MSG-6`: implemented. `langsys.messages.category` maps onto the core's `messages_category`, and runtime registration uses it: `Messages/MigrateModeTest::testTheConfiguredCategoryIsWhereTemplatesAreRegistered`. The listing command registers under the same category, `Messages/MessagesCommandTest::testRegisterSendsWhatTheCatalogLacks`. |
| MSG-7 | implemented | n/a (pure) | `php artisan langsys:messages` lists every rule of every FormRequest a route's controller action takes, with each field's label: `Messages/MessagesCommandTest::testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `::testACustomMessageIsListedAsTheAppWroteIt`. A listed template is built by the same code as the entry a failing request sends, and what a request sends is what was listed, `::testWhatARequestSendsIsWhatWasListed`, R1. What cannot be listed is named with its fix, `::testWhatCannotBeListedIsNamedWithItsFix`, S1, S2, S7, S9. It reports and exits 0 by default, 1 under `--strict`, `::testTheCommandReportsByDefaultAndFailsUnderStrict`, K1. `--register` sends what the catalog lacks through the core's contract-proven `MessageCatalogCommand::register()`, `::testRegisterSendsWhatTheCatalogLacks`, K2. Core `MSG-7`: implemented. |
| MSG-8 | delegated | - | Core `MSG-8`: implemented. A failure's template goes to the core's `emitMessage()`, which registers what the catalog lacks after the response: `Messages/MigrateModeTest::testATemplateTheCatalogLacksIsQueuedForRegistration`. A failing client leaves Laravel's messages as they are, `::testAFailingClientLeavesLaravelsMessages`. |
| MSG-9 | implemented | n/a (pure) | Each entry is built from the rule that failed and its parameters — Laravel's unfilled line, read through Laravel's own `getMessage()` and replacer — never from the rendered bag: `Messages/ValidatorMessagesTest::testOneEntryPerFailedRule`, `::testAFilledTemplateReproducesLaravelsOwnMessage`. A failure that arrives as text only registers as that text with no params, `::testATextOnlyFailureCarriesNoCode`, and the listing names text-only messages it meets. |
| MSG-10 | implemented | n/a (pure) | The label is the one Laravel prints: `attributes()` where declared, else Laravel's derived name, both through Laravel's `getDisplayableAttribute()`: `Messages/ValidatorMessagesTest::testLabelsAreWrittenInAndNoPlaceholderSurvives`. The listing names a field with no declared label, with the name Laravel prints instead, without failing the build: `Messages/MessagesCommandTest::testAFieldWithNoDeclaredLabelIsNamed`, A1. |
| MSG-11 | delegated | - | Core `MSG-11`: implemented. The listing adds every template through the core's `MessageCatalog::add()`, which refuses a Laravel label placeholder and any placeholder left unfilled: `Messages/MessagesCommandTest::testWhatCannotBeListedIsNamedWithItsFix` meets the refusal for `:thing`. The fill-time warning is the core's, at `emitMessage()`. |
| MSG-12 | implemented | n/a (pure) | A failed form that redirects hands its entries to the next page as an Inertia prop, beside Laravel's untouched `errors`: `Messages/InertiaHandoffTest::testTheEntriesReachTheNextPageAsAProp`, `::testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation`, and a page after no failure carries nothing, `::testAPageWithNoFailureCarriesNothing`. Inertia is a development dependency only. |
| MIG-1 | implemented | n/a (pure) | Keep mode, with nothing configured: Laravel's own translator answers and the core is given no migration files, so no key is looked up: `Translation/KeepModeTranslatorTest::testLaravelsOwnTranslatorAnswers`, `::testTheClientIsBuiltWithNoMigrationFiles`, P1, P2. Core `MIG-1`: implemented. |
| MIG-2 | implemented | n/a (pure) | Key first: a key a file holds renders its line's translation, `Translation/MigrateTranslatorTest::testAGroupKeyRendersTheCatalogTranslationOfItsSourceLine`. A miss is literal text, converted by the Laravel call that received it through the core's `LegacyValue::fromCall()`: `::testASentenceNoFileHoldsIsConvertedAndRegisteredAsWritten`, `::testASentenceKeepsWhatLaravelWouldPrintAsWritten`, `::testAPipePluralNoFileHoldsIsConvertedToo`, `::testACapitalisingPlaceholderIsRegisteredAsWrittenAndWarned`. T2, T8, T9, T10. Core `MIG-2`: implemented. |
| MIG-3 | implemented | n/a (pure) | The phrase registered through a key is its source line, never the key: `Translation/MigrateTranslatorTest::testAMissRegistersTheSourceLineUnderTheGroupNeverTheKey`, `::testAJsonKeyResolvesToItsLine`. Core `MIG-3`: implemented. |
| MIG-4 | delegated | - | Core `MIG-4`: implemented. Conversion is the core's; through `__()` a Laravel plural becomes one ICU plural over `count`, `Translation/MigrateTranslatorTest::testAPipePluralRendersThroughIcuOverCount`, `::testAJsonPluralRendersThroughIcuOverCount`. |
| MIG-5 | delegated | - | Core `MIG-5`: implemented. The binding passes no category, so the group is the category: `Translation/MigrateTranslatorTest::testAMissRegistersTheSourceLineUnderTheGroupNeverTheKey`, `::testTheFrameworksOwnLinesAnswerWhatTheAppDoesNotDefine`. |
| MIG-6 | delegated | - | Core `MIG-6`: implemented. The binding treats no drift of its own; a key no file holds reaches the core as literal text. |
| MIG-7 | implemented | n/a (pure) | The core is given the files Laravel's own loader reads in the source locale, in Laravel's order and tiers: the app's JSON (declared `laravel`) then its groups; the framework's bundled English and package JSON as fallback; a package's `lang/vendor` override ahead of its own files; `validation.php` in no tier. `Translation/MigrateTranslatorTest::testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale`, `::testValidationLinesAreNotMigrationSourceFiles`, `::testAPackageKeyResolvesThroughItsNamespaceWithTheAppsOverride`, F1 to F8. Core `MIG-7`: implemented. |
| MIG-8 | implemented | n/a (pure) | In migrate mode Laravel's `translator` is this package's subclass: `__()`, `trans()`, `@lang` and `trans_choice()` go to `translate()` in the core's migration mode with no call site changed. Validation keys stay with Laravel, `Translation/MigrateTranslatorTest::testValidationKeysStayWithLaravel`, T1, T7; `has()` asks the core whether a file holds the key, `::testHasAnswersWhetherAFileHoldsTheKey`, T5; the locale is the request's, normalized, `::testAnExplicitLocaleIsNormalizedBeforeTheLookup`, T6; resolving the translator builds no client, `::testResolvingTheTranslatorDoesNotBuildTheClient`, P3. |
| MIG-9 | delegated | - | Core `MIG-9`: **not implemented** — the import waits on the `translations` map the 907 merge brings to `POST /api/translatable-items`. Counts red here until the core's row turns green. The binding sends nothing of its own: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. Gap 3. |
| SNAP-1 | delegated | - | Core `SNAP-1`: implemented; the export is the core's `vendor/bin/langsys-snapshot`. The binding exports nothing and adds no endpoint: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| SNAP-2 | implemented | n/a (pure) | `langsys.snapshot` names a snapshot file the provider hands the core's `snapshot` option, so a lookup answers with no network: `SnapshotTest::testAConfiguredSnapshotAnswersWithNoNetwork`, with its control `::testWithoutASnapshotTheLookupFallsBackToSource`, N1. Lookups, precedence and the offline served set are the core's, core `SNAP-2`: implemented. |
| SNAP-3 | delegated | - | Core `SNAP-3`: implemented; `Snapshot::load()` refuses an edited snapshot. The binding reports the refusal and builds the client without it, never failing a request: `SnapshotTest::testASnapshotTheCoreRefusesIsReportedAndSkipped`, N2, N3. |
| BIND-1 | implemented | n/a (pure) | **Shape and timing only.** Timing: `terminate()` and the long-lived boundaries decide *when* the core flushes and resets. Shape: the Laravel cache adapter, the casing of Laravel's own locale store, the Inertia prop shape, route scoping for `TranslateResponse`, Laravel's translator and validator hooks, the entries' place and piece names in Laravel's error body, and the file list the migration mode reads. Everything that is meaning is delegated and proven absent by the three scans in `BindingBoundaryTest`, each with firing control `BindingBoundaryTest::testEveryScanFiresOnAPlantedViolationAndNotOnAComment` and coverage control `::testTheScanReadsEverySourceFile`. Pass-through probes cover both render routes: `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` and `TranslateResponseTest::testServesExactlyWhatTheSdkReturned`. M7a, M7b, M7c, M9, M10, M16b. |
| BIND-2 | implemented | n/a (pure) | `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability` finds no `write_enabled`, `key_type`, `canWrite`, `auto_discovery` or `ip_write` in code. Firing control: `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`. M16a plants `canWrite()` and reddens the scan. |
| BIND-3 | implemented | n/a (pure) | `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` finds no transport, auth or grant header, hint endpoint, batching, registration construction or timer. Firing control: `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`. M16c plants `usleep()` and reddens the scan. Flushing at a lifecycle boundary is timing under BIND-1, not scheduling. `DetectLocale`'s `Set-Cookie` and `Vary` are headers on the application's own response, not on a request to Langsys. |
| BIND-4 | implemented | n/a (pure) | `BindingBoundaryTest::testEveryConfigKeyIsACoreOptionOrLaravelWiring` pins every key in `config/langsys.php` with its classification: a core option mapped onto Laravel, SRV-6 wiring (where Laravel keeps a locale value, which sources it reads and how it narrows the project's locales), or whether and where Laravel invokes the core. Firing control: `::testTheConfigCheckSeesAnAddedKey`. M17 re-adding `auto_flush` reddens the pin. `auto_flush` is removed, pending the operator's ruling: it was product configuration the core does not define, and under PHP-FPM it never stopped a flush. |
| BIND-5 | implemented | n/a (pure) | `TranslateResponseTest::testEveryRequestReachesTheSdk`: two identical requests both reach `translatePage()`, and M10 memoizing the page reddens the test. The page cache is removed, pending the operator's ruling. `LaravelCacheAdapter` is the core's own cache mapped onto a Laravel store, not a binding cache, and key presence survives it: `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. |
| BIND-6 | implemented | n/a (pure) | `BindingBoundaryTest::testThePublicSurfaceIsTheOneArguedForHere` pins every public method each class declares, plus `t()`; M18 adding one reddens it. `Langsys::client()` re-exports the core `Client` by reference, `FacadeTest::testClientReturnsTheContainersSdkClient`. Every new name is a framework idiom: middleware `handle` and `terminate`, Inertia's `share`, an Artisan command's `handle`, Laravel's translator methods (`get`, `choice`, `has`), a cache-store adapter, a validator subclass, the core's `MessageSource` for Laravel's FormRequests, and `LocaleFormatter::canonicalize` for Laravel's own locale store. |
| GRANT-1 | n/a (profile: browser) | - | A server binding holds a write key, and a server SDK must not send `X-Write-Grant`. This package sets no request header of any kind: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `x-write-grant`. |
| GRANT-2 | n/a (profile: browser) | - | As GRANT-1. |
| GRANT-3 | n/a (profile: browser) | - | As GRANT-1. |
| GRANT-4 | n/a (profile: browser) | - | As GRANT-1. |
| CACHE-1 | implemented | n/a (pure) | The keys this package writes itself are scoped to the project: `LaravelCacheAdapter`'s key index carries the project id, so clearing one project's cache leaves another's: `LaravelCacheAdapterTest::testClearingOneProjectLeavesAnotherProjectsKeys`, and on the route an application takes, `ServiceProviderTest::testTheProviderScopesTheCacheIndexToTheProject`. M11. The core's own keys pass through verbatim with the core's namespacing, core `CACHE-1`: implemented. An adapter constructed by hand without `$projectId` keeps an unscoped index; the service provider never builds one that way. |
| CACHE-2 | delegated | - | Core `CACHE-2`: implemented; the failure window lives on the `Client` and survives `resetRequestState()`, so it carries across Octane requests. The binding fetches nothing itself: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| OBS-1 | delegated | - | Core `OBS-1`: implemented. Emitting it needs the capability the binding may not read (BIND-2): `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`. |
| WIRE-1 | delegated | - | Core `WIRE-1`: implemented. The binding sets no request header: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `x-authorization` and transport. |
| WIRE-2 | delegated | - | Core `WIRE-2`: implemented. The binding parses no response: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| WIRE-3 | implemented | n/a (pure) | Every locale this package hands the core is the lowercase `xx-yy` both SDKs identify a locale by. `t()` normalizes before `translate()`, whose catalog is keyed by the locale it is handed: `LangsysTranslatorTest::testEveryHostSpellingOfALocaleReadsTheSameCatalog` reads one catalog entry from `es-es`, `es-ES`, `es_ES` and `ES-es` on the real core, M6. Migrate-mode `__()` goes through the same path, `Translation/MigrateTranslatorTest::testAnExplicitLocaleIsNormalizedBeforeTheLookup`, T6. The Inertia hand-off sends `es-es`, a casing pending the operator's ruling: `InertiaSsrPropsTest::testEveryHostSpellingHandsTheSdkForm`, M15. `DetectLocale` maps a framework locale through the core, SRV-6. Canonical BCP 47 remains only in Laravel's own `app()->getLocale()`, the host locale store this rule describes. For categories, the binding passes `null` and never spells `__uncategorized__`, M7c. The wire is the core's, core `WIRE-3`: implemented. |
| WIRE-4 | implemented | n/a (pure) | Proven on the real core with the API genuinely unreachable — a refused connection to a closed local port — on every entry point this package exposes. `t()` renders the interpolated source phrase and queues nothing, `LangsysTranslatorTest::testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing`, M21. `TranslateResponse` serves the untranslated page, `TranslateResponseTest::testAnUnreachableApiServesTheUntranslatedPage`. A boundary flush fails nothing, `RequestScopeTest::testAFlushThatCannotReachTheApiFailsNothing`. `InertiaSsrProps::share()` hands no seed instead of a 500, `InertiaSsrPropsTest::testAnUnreachableApiHandsNoSeedInsteadOfThrowing`, M13. `DetectLocale` leaves Laravel's locale alone when the project's locales cannot be read, `DetectLocaleTest::testAnUnreachableProjectLeavesTheAppLocaleAlone`, L12. A validation failure with a failing client keeps Laravel's messages, `Messages/MigrateModeTest::testAFailingClientLeavesLaravelsMessages`. Wherever the core degrades, the binding adds no catch of its own: `testDoesNotSwallowAFailureTheSdkLetThrough` in both `LangsysTranslatorTest` and `TranslateResponseTest`, M7b, M9. |
| WIRE-5 | delegated | - | Core `WIRE-5`: implemented. This package maps it onto `langsys.api_url` and `LANGSYS_API_URL`. `ServiceProviderTest::testTheConfiguredApiUrlIsWhereTheSdkConnects` observes the configured address in a real connection attempt, and `::testAnApiUrlChangedAfterTheClientIsBuiltIsNotUsed` shows a change after the client is built reaches nothing. M20. |
| CONF-1 | provisional | mock | Tests assert an observable consequence wherever this package's evidence allows: the next unit of work's state, the served bytes, the core's own pending queue, the entries in the response. Rows name each Laravel route a rule was proven on — PHP-FPM, Octane, queue job finished and thrown, `t()`, `__()`, `translatePage()` and a failed validation. The rows whose half is the server's answer are `provisional`. Waits on: the shared contract fixture, vendored here with a harness after the core's `ContractTestCase`. |
| CONF-2 | implemented | n/a (pure) | Every row carries exactly one status and one tier, and the pairing is checked mechanically. The script under *Computed summary* exits non-zero on any missing, duplicated or unknown rule id, and on an unrecognised status or tier. It rejects an `implemented` row whose tier is not `live`, `contract` or `n/a (pure)`, and a `provisional` row whose tier is not `mock` or that does not name what it waits on. A `delegated` row must carry tier `-`, and the summary resolves it against the core's current grade in `../langsys-php-sdk/CONFORMANCE.md`, as the fleet checker does. |
| CONF-3 | implemented | n/a (pure) | Every guard this package owns was mutated in place in a git worktree bound to the core by provenance, and every mutant reddened a named test; see *Mutation record*. Each test builds a fresh application and binds its own `Client`, and the Octane leg's stand-in event is recorded under GATE-3. |

## Pending the operator's ruling

These changes are in the code at this tip, but the operator holds the sign-off. Each rests on a binding rule, and what each row becomes if the change is reverted is stated here, so a reversal regrades the rows instead of silently invalidating them. The restore paths are in `ROADMAP.md`.

- **Removal of `auto_flush`.** Rests on it: BIND-4. **If restored:** BIND-4 is no longer green unless the operator records a waiver, and `BindingBoundaryTest::testEveryConfigKeyIsACoreOptionOrLaravelWiring` gains the key. Under PHP-FPM the setting would still not stop a flush, because the core's shutdown handler sends the queue regardless. It must never gate the long-lived reset, or GATE-3 and SRV-2 regress.
- **Removal of the `TranslateResponse` page cache.** Rests on it: BIND-5, CACHE-1, GATE-4. **If restored as a waiver:** BIND-5 becomes `waived`, citing the operator's recorded agreement. The restored key must carry the project id or CACHE-1 fails. GATE-7 gains a recorded limit — a cache hit feeds no lane — and `TranslateResponseTest::testEveryRequestReachesTheSdk` narrows to the cache-disabled case.
- **Lowercase `initialTranslationsLocale`.** Rests on it: WIRE-3 and SRV-4. **If `es-ES` is kept:** both stay green, because the JS core canonicalizes either spelling on receipt. WIRE-3's "every locale this package hands the core" narrows to "every PHP-core boundary", and M15's tests revert to canonical expectations.

**Relayed, and not built until the operator confirms them in this package's session:** server-rendered pages printing translated validation errors inside the resolved marker (MSG-5, GATE-10), the JSON 422 double response, the resolved-marker Blade directive (GATE-10), the Blade fragment directive, the install command, `fill` mode, and system messages as their own slice. Each row they would move says so.

## Gaps, ranked by cost

Ranked by what each gap costs someone running the Laravel stack, not by rule order.

1. **GATE-10 — translated text printed inline carries no resolved marker.** A Blade page in a non-base locale that uses `@t`, `t()` or migrate-mode `__()`, with a JS SDK loaded, hands that SDK translated text it reads as source. Where the project's discovery gate (GATE-9) is off, the client registers translations as new source phrases. The fix is the resolved-marker Blade directive.
2. **MSG-5 — Blade-only pages show validation errors in the source language.** Entries carry source templates for a client to render; a page with no JS SDK has no client to render them.
3. **MIG-9 — no import of existing translations.** The core's, waiting on the 907 merge. Until then an application migrating keeps its translations only by entering them in the Translation Manager, or lets machine translation fill them.
4. **CONF-1 — the shared contract fixture is not vendored here.** SRV-1, SRV-3, SRV-6 and CONF-1 stay `provisional` until this package runs its API-dependent halves against it.
5. **MSG-7 — rule objects are not listable.** `Password`, `Enum` used as an object, and every application rule object register at first send (MSG-8), so the first user to fail one sees it in the source language.

## Release gate

- **This tip needs the 838 core, and the core is untagged.** The code calls `resetRequestState()`, `resolveRequestLocale()`, `resolveLegacyKey()`, `LegacyValue::fromCall()`, `emitMessage()` and the snapshot seam, none of which v1.3.1 has, and `^1.3` still resolves to v1.3.1 from Packagist. So CI, which installs from Packagist, fails on this branch by design. At publication, the constraint moves to the core's 838 tag.
- **The local path repository comes out at publication.** Locally, `composer.json` carries an uncommitted path repository to `../langsys-php-sdk`, recorded in `ROADMAP.md`.

## Mutation record

Each mutant was applied in place in a `git worktree` of this tip, with `vendor/langsys/langsys-php` linked to core `544b24f` and that binding asserted by reflection before the run, then run against the whole suite and reverted. **All 86 reddened a named test; none survived.** Run 2026-09-27. The first column's letter names the area: `M` the lifecycle, translator, page, cache and Inertia guards; `T`, `F` and `P` the migrate-mode translator, its file list and its wiring; `L` the request locale; `C` and `V` entry codes; `S`, `K` and `R` the listing source, the command and runtime agreement; `A`, `E` and `N` label advice, piece names and the snapshot.

| Mutant | What it breaks | Reddened |
|---|---|---|
| M1 | no reset at boundary | `testEveryBoundaryFlushesAndThenResets`, `testTheNextUnitOfWorkStartsWithoutAWriteDecision`, `testTheNextUnitOfWorkReadsTheCatalogAfresh`, `testAFlushThatCannotReachTheApiFailsNothing`, and 1 more |
| M2 | reset before flush | `testEveryBoundaryFlushesAndThenResets`, `testAFlushThatCannotReachTheApiFailsNothing`, `testASkippedWriteAtABoundaryIsNamedByTheCore` |
| M3 | boundary builds unused Client | `testABoundaryNeverBuildsAClientNobodyUsed` |
| M4 | thrown job not a boundary | `testEveryBoundaryFlushesAndThenResets`, `testTheNextUnitOfWorkStartsWithoutAWriteDecision`, `testTheNextUnitOfWorkReadsTheCatalogAfresh` |
| M5 | Octane not a boundary | `testEveryBoundaryFlushesAndThenResets`, `testTheNextUnitOfWorkStartsWithoutAWriteDecision`, `testTheNextUnitOfWorkReadsTheCatalogAfresh` |
| M6 | translator stops normalizing | `testReturnsExactlyWhatTheSdkReturned` |
| M7a | translator re-interpolates | `testReturnsExactlyWhatTheSdkReturned` |
| M7b | translator re-adds a catch | `testDoesNotSwallowAFailureTheSdkLetThrough` |
| M7c | translator spells sentinel | `testTheBindingReimplementsNoIdentityOrRenderingBehaviour` |
| M8 | flush on the request path | `testDiscoveredPhrasesAreFlushedAfterTheResponse`, `testTheFlushRunsOnlyOnceTheResponseExists` |
| M9 | middleware re-adds a catch | `testDoesNotSwallowAFailureTheSdkLetThrough` |
| M10 | middleware memoizes pages | `testSetsTheClientLocaleFromTheAppLocale`, `testPassesTheConfiguredCategory`, `testExceptPathsAreExcluded`, `testOnlyPathsRestrictScope`, and 4 more |
| M11 | cache index unscoped | `testClearingOneProjectLeavesAnotherProjectsKeys`, `testTheProviderScopesTheCacheIndexToTheProject` |
| M12 | adapter TTL back to 3600 | `testTheConfiguredTtlAppliesWhenTheSdkPassesNone` |
| M13 | Inertia hand-off loses its catch | `testAnUnreachableApiHandsNoSeedInsteadOfThrowing` |
| M14 | Inertia re-reads the shared cache | `testHandsTheClientTheCatalogThisRequestRenderedWith` |
| M15 | Inertia canonical-cased | `testBuildsTheJsSdkSeedingShape`, `testExplicitLocaleOverridesTheAppLocale`, `testEveryHostSpellingHandsTheSdkForm` |
| M16a | canWrite planted | `testHelperTranslatesAndInterpolates`, `testHelperFallsBackToThePhraseAndQueuesIt`, `testHelperDefaultsToTheAppLocale`, `testRenderedOutputIsEscaped`, and 37 more |
| M16b | md5 planted | `testTheBindingReimplementsNoIdentityOrRenderingBehaviour` |
| M16c | usleep planted | `testTheBindingOwnsNoNetworkBehaviour` |
| M17 | auto_flush re-added | `testEveryConfigKeyIsACoreOptionOrLaravelWiring` |
| M18 | new public method | `testThePublicSurfaceIsTheOneArguedForHere` |
| M19 | DetectLocale never sets app locale | `testABareLanguageCandidateMapsToTheProjectsDefault`, `testWithNothingResolvedTheQueryWinsAndPersistsToTheCookie`, `testTheCookieBeatsTheHeaderAndTheResponseVariesOnCookie`, `testASessionValueIsAStoredLocale`, and 8 more |
| M20 | provider ignores api_url | `testTheConfiguredApiUrlIsWhereTheSdkConnects`, `testAnApiUrlChangedAfterTheClientIsBuiltIsNotUsed` |
| M21 | translator throws where SDK degrades | `testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing`, `testAConfiguredSnapshotAnswersWithNoNetwork`, `testWithoutASnapshotTheLookupFallsBackToSource`, `testASnapshotTheCoreRefusesIsReportedAndSkipped` |
| T1 | validation keys go to Langsys | `testAFailingClientLeavesLaravelsMessages`, `testTheEntriesReachTheNextPageAsAProp`, `testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation`, `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, and 11 more |
| T2 | miss not converted | `testASentenceNoFileHoldsIsConvertedAndRegisteredAsWritten`, `testAPipePluralNoFileHoldsIsConvertedToo` |
| T3 | hit choice without count | `testAPipePluralRendersThroughIcuOverCount`, `testAJsonPluralRendersThroughIcuOverCount`, `testATranslatedPluralSelectsForTheRequestLocale` |
| T8 | choice miss read as __ | `testAPipePluralRendersThroughIcuOverCount`, `testAJsonPluralRendersThroughIcuOverCount`, `testAPipePluralNoFileHoldsIsConvertedToo`, `testATranslatedPluralSelectsForTheRequestLocale` |
| T9 | no warning | `testACapitalisingPlaceholderIsRegisteredAsWrittenAndWarned` |
| T10 | miss converted by file table | `testASentenceKeepsWhatLaravelWouldPrintAsWritten` |
| T4 | countable not counted | `testAPipePluralRendersThroughIcuOverCount` |
| T5 | has() inherited | `testHasAnswersWhetherAFileHoldsTheKey` |
| T6 | explicit locale ignored | `testAnExplicitLocaleIsNormalizedBeforeTheLookup` |
| T7 | choice validation to Langsys | `testValidationKeysStayWithLaravel` |
| F1 | validation.php included | `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale`, `testValidationLinesAreNotMigrationSourceFiles` |
| F2 | app groups dropped from own tier | `testMigrateModeListsTheLangFilesProblems`, `testAGroupKeyRendersTheCatalogTranslationOfItsSourceLine`, `testAMissRegistersTheSourceLineUnderTheGroupNeverTheKey`, `testANestedKeyResolvesByPath`, and 6 more |
| F3 | app path not excluded from fallback | `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F4 | tiers swapped | `testMigrateModeListsTheLangFilesProblems`, `testTheFrameworksOwnLinesAnswerWhatTheAppDoesNotDefine`, `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F5 | namespace tiers swapped | `testAPackageKeyResolvesThroughItsNamespaceWithTheAppsOverride`, `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F6 | JSON dropped | `testAJsonKeyResolvesToItsLine`, `testAJsonPluralRendersThroughIcuOverCount`, `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F7 | JSON after groups | `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F8 | JSON format undeclared | `testAJsonPluralRendersThroughIcuOverCount`, `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| P1 | migration always on | `testTheClientIsBuiltWithNoMigrationFiles` |
| P2 | translator in every mode | `testLaravelsOwnTranslatorAnswers` |
| P3 | eager Client | `testAMissRegistersTheSourceLineUnderTheGroupNeverTheKey`, `testAJsonKeyResolvesToItsLine`, `testASentenceNoFileHoldsIsConvertedAndRegisteredAsWritten`, `testASentenceKeepsWhatLaravelWouldPrintAsWritten`, and 8 more |
| P4 | fallback locale dropped | `testTheServerNeverEmitsTranslatedText` |
| L1 | resolved flag ignored | `testALocaleTheAppResolvedIsServedAsItIs`, `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase` |
| L2 | listener dropped | `testALocaleTheAppResolvedIsServedAsItIs`, `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase` |
| L3 | app locale not mapped | `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase` |
| L4 | app branch ignores client | `testALocaleTheAppResolvedIsServedAsItIs`, `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase` |
| L15 | defaults dropped from match | `testABareLanguageCandidateMapsToTheProjectsDefault` |
| V1 | text entry gets a code | `testATextOnlyFailureCarriesNoCode` |
| L5 | order ignored | `testTheSourcesAreAskedInTheConfiguredOrder` |
| L6 | supported ignored | `testTheSupportedListNarrowsTheProjectsLocales` |
| L7 | project not validated | `testABareLanguageCandidateMapsToTheProjectsDefault`, `testTheSupportedListNarrowsTheProjectsLocales`, `testAnUnsupportedCookieFallsThroughAndIsNotReset` |
| L8 | no vary | `testTheCookieBeatsTheHeaderAndTheResponseVariesOnCookie`, `testASessionValueIsAStoredLocale`, `testTheHeaderIsNegotiatedAndTheResponseVariesOnIt`, `testTheSourcesAreAskedInTheConfiguredOrder`, and 1 more |
| L9 | header-read vary dropped | `testWithNothingUsableTheBaseLocaleIsServed` |
| L10 | cookie varies as query | `testTheCookieBeatsTheHeaderAndTheResponseVariesOnCookie`, `testASessionValueIsAStoredLocale` |
| L11 | persist any source | `testTheSupportedListNarrowsTheProjectsLocales`, `testAnUnsupportedCookieFallsThroughAndIsNotReset` |
| L12 | unreachable project still resolves | `testAnUnreachableProjectLeavesTheAppLocaleAlone` |
| L13 | session persist dropped | `testAQueryChoicePersistsToTheSessionWhenConfigured` |
| L14 | SDK sends Vary | `testTheClientNeverSendsVaryItself` |
| C1 | studly code | `testTheEntriesReachTheNextPageAsAProp`, `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testACustomMessageIsListedAsTheAppWroteIt`, `testWhatARequestSendsIsWhatWasListed`, and 10 more |
| C2 | class code snaked | `testARuleObjectOrClosureCarriesTheClassLaravelRecords` |
| S1 | wildcard label check off | `testWhatCannotBeListedIsNamedWithItsFix` |
| S2 | rule objects silent | `testWhatCannotBeListedIsNamedWithItsFix` |
| S3 | silent rules not skipped | `testWhatCannotBeListedIsNamedWithItsFix` |
| S4 | value not seeded | `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testWhatARequestSendsIsWhatWasListed` |
| S5 | messages dropped | `testACustomMessageIsListedAsTheAppWroteIt`, `testWhatARequestSendsIsWhatWasListed`, `testWhatCannotBeListedIsNamedWithItsFix` |
| S6 | labels dropped | `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testWhatARequestSendsIsWhatWasListed`, `testVerboseListsEachTemplate` |
| S7 | unbuildable swallowed | `testWhatCannotBeListedIsNamedWithItsFix` |
| S8 | routes ignored | `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testACustomMessageIsListedAsTheAppWroteIt`, `testWhatARequestSendsIsWhatWasListed`, `testWhatCannotBeListedIsNamedWithItsFix`, and 4 more |
| S9 | no-line rule silent | `testWhatCannotBeListedIsNamedWithItsFix` |
| K1 | strict ignored | `testTheCommandReportsByDefaultAndFailsUnderStrict` |
| K2 | register skipped | `testRegisterSendsWhatTheCatalogLacks` |
| K3 | legacy source dropped | `testMigrateModeListsTheLangFilesProblems` |
| K4 | verbose silent | `testVerboseListsEachTemplate` |
| K5 | problems silent | `testMigrateModeListsTheLangFilesProblems`, `testTheCommandReportsByDefaultAndFailsUnderStrict` |
| K6 | command unregistered | `testMigrateModeListsTheLangFilesProblems`, `testTheCommandReportsByDefaultAndFailsUnderStrict`, `testVerboseListsEachTemplate`, `testRegisterSendsWhatTheCatalogLacks` |
| R1 | runtime diverges | `testTheEntriesReachTheNextPageAsAProp`, `testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation`, `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testACustomMessageIsListedAsTheAppWroteIt`, and 10 more |
| A1 | label advice off | `testAFieldWithNoDeclaredLabelIsNamed` |
| E1 | pieces ignored | `testThePieceNamesAreConfigurable` |
| N1 | snapshot not passed | `testAConfiguredSnapshotAnswersWithNoNetwork`, `testASnapshotTheCoreRefusesIsReportedAndSkipped` |
| N2 | refusal not reported | `testASnapshotTheCoreRefusesIsReportedAndSkipped` |
| N3 | refusal throws | `testASnapshotTheCoreRefusesIsReportedAndSkipped` |

M20 substitutes a second closed port rather than removing the setting: removing it would point a mutant at the production API.

## Raised against the spec

Measured here and raised through the Reviewer; not findings against this package.

- **SRV-2:** its test calls sequential renders proof of nothing, but in a per-request runtime the interleave cannot occur, and sequential reuse across a boundary is the failure that exists. It is worth asking whether the test wording should admit that.

## Computed summary

Produced by the script below, run from the repository root with `langsys2` and `langsys-php-sdk` checked out alongside. It exits non-zero on a malformed file. A red grade is a fact about the SDK, not a defect in this file, so it is reported rather than failed on.

```
spec blob, re-derived             7eee2c10398a1032831837c310215f3b9f16d306
rule ids in the spec              114
rowed exactly once                114
missing / duplicated / unknown    0 / 0 / 0
malformed rows                    0
as graded in this file
  implemented                     28
  delegated                       53
  n/a                             28
  provisional                     4
  waived                          0
  partial                         0
  not implemented                 1
  held (strip ruling)             0
delegated rows resolved against langsys-php-sdk 544b24f
  implemented                     52
  not implemented                 1
counting red                      GATE-10, MIG-9
GREEN, provisional counted apart  no
```

```python
import collections, re, subprocess, sys

T = '234eab14cd8787ad9b4a8c51287590865adff69a'
git = lambda *a: subprocess.run(['git', '-C', '../langsys2', *a], capture_output=True, text=True, check=True).stdout
blob = git('rev-parse', f'{T}:docs/sdk-spec.mdx').strip()
ids = re.findall(r'^### ([A-Z]+-\d+) —', git('show', f'{T}:docs/sdk-spec.mdx'), re.M)

doc = open('CONFORMANCE.md').read()
STATUS = re.compile(r'^(implemented|provisional|delegated|partial|not implemented|held \(strip ruling\)|waived'
                    r'|n/a \(profile: [a-z]+\)|n/a \(architecture: [^()]+\))$')
TIERS = {'live', 'contract', 'mock', 'n/a (pure)', '-'}

seen, grade, bad, in_table = collections.Counter(), {}, [], False
for line in doc.splitlines():
    if line.startswith('| Rule | Status | Tier | Evidence |'):
        in_table = True
        continue
    if in_table and not line.startswith('|'):
        in_table = False
    if not in_table or line.startswith('|---'):
        continue
    cells = [c.strip() for c in line.strip().strip('|').split('|')]
    if len(cells) != 4:
        bad.append((cells[0], f'{len(cells)} cells'))
        continue
    rid, status, tier, evidence = cells
    seen[rid] += 1
    grade[rid] = 'n/a' if status.startswith('n/a') else status
    if not STATUS.match(status):
        bad.append((rid, f'status {status!r}'))
    if tier not in TIERS:
        bad.append((rid, f'tier {tier!r}'))
    if status == 'implemented' and tier not in ('live', 'contract', 'n/a (pure)'):
        bad.append((rid, 'implemented without live, contract or n/a (pure)'))
    if status == 'provisional' and (tier != 'mock' or 'Waits on: the shared contract fixture' not in evidence):
        bad.append((rid, 'provisional without mock and its waits-on clause'))
    if not evidence:
        bad.append((rid, 'no evidence'))
    if status in ('delegated', 'not implemented') or status.startswith('n/a'):
        if tier != '-':
            bad.append((rid, f'{status} without tier -'))

# A delegated row is graded by the core row it rests on, as the fleet checker grades it.
core_doc = open('../langsys-php-sdk/CONFORMANCE.md').read()
core_sha = subprocess.run(['git', '-C', '../langsys-php-sdk', 'rev-parse', '--short', 'HEAD'], capture_output=True, text=True, check=True).stdout.strip()
core = {}
for line in core_doc.splitlines():
    m = re.match(r'^\|([^|]+)\|([^|]+)\|', line)
    if not m:
        continue
    cell, st = m.group(1), m.group(2).replace('*', '').strip()
    got = set(re.findall(r'\b[A-Z]+-\d+\b', cell))
    for r in re.finditer(r'([A-Z]+)-(\d+)\s*…\s*(?:[A-Z]+-)?(\d+)', cell):
        got |= {f'{r.group(1)}-{n}' for n in range(int(r.group(2)), int(r.group(3)) + 1)}
    for rid in got:
        core[rid] = 'n/a' if st.startswith('n/a') else st
resolved = {}
for rid, g in grade.items():
    if g == 'delegated' and rid not in core:
        bad.append((rid, 'delegated to a core row that does not exist'))
    resolved[rid] = core.get(rid, 'unresolved') if g == 'delegated' else g

missing = [i for i in ids if seen[i] == 0]
duplicated = [i for i in ids if seen[i] > 1]
unknown = sorted(set(seen) - set(ids))
tally = collections.Counter(grade.values())
if f'docs/sdk-spec.mdx blob {blob}' not in doc:
    bad.append(('header', f'does not cite the derived blob {blob}'))
red = [i for i in ids if resolved.get(i) in ('partial', 'not implemented', 'held (strip ruling)')]

print(f'spec blob, re-derived             {blob}')
print(f'rule ids in the spec              {len(ids)}')
print(f'rowed exactly once                {sum(seen[i] == 1 for i in ids)}')
print(f'missing / duplicated / unknown    {len(missing)} / {len(duplicated)} / {len(unknown)}')
print(f'malformed rows                    {len(bad)}')
print('as graded in this file')
for g in ('implemented', 'delegated', 'n/a', 'provisional', 'waived', 'partial', 'not implemented', 'held (strip ruling)'):
    print(f'  {g:<32}{tally.get(g, 0)}')
print(f'delegated rows resolved against langsys-php-sdk {core_sha}')
for g in sorted({resolved[i] for i in ids if grade.get(i) == 'delegated'}):
    print(f'  {g:<32}{sum(1 for i in ids if grade.get(i) == "delegated" and resolved[i] == g)}')
print(f'counting red                      {", ".join(red) if red else "none"}')
print(f'GREEN, provisional counted apart  {"yes" if not red and not (missing or duplicated or unknown or bad) else "no"}')
for problem in missing + duplicated + unknown + bad:
    print('  problem:', problem)
sys.exit(1 if (missing or duplicated or unknown or bad) else 0)
```
