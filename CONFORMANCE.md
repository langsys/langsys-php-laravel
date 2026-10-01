# Conformance — langsys/langsys-php-laravel

| **Spec revision read** | langsys2 bb41605e362c84bb853345f54f7230f934b164b6, docs/sdk-spec.mdx blob 295974b4c2c9e63af82d48513514f5b78e6c088a |
|---|---|
| **Profiles** | server, binding, all — derived: binding over langsys-php-sdk |
| **Spec version** | 8.5.5, committed at `bb41605e362c84bb853345f54f7230f934b164b6` and unpublished |
| **Core bound against** | langsys-php-sdk `9ff7b35` (`v1.3.1-88`, `feature/838_write_key_gating_reland`, untagged), whose conformance file is canonical — see *Release gate* |
| **Derived** | 2026-09-30T20:08:57Z by `git rev-parse` on the commit above; the text graded is byte-identical to the text read |

**The profile is derived.** The spec's rules name Laravel as an example throughout, but no profile line names a PHP framework binding, so this package takes its core's profiles (`server`, `all`) and adds `binding`.

Every rule id in the spec is rowed exactly once, including the ones that do not bind this package. A row graded `implemented` names a test that fails when the behaviour is removed; the mutation that proves it is in *Mutation record*. `delegated` names the core row it rests on and the absence probe that shows this package does not do the work itself, with a firing control that shows the probe can fail. `contract` rows are proven against the shared contract fixture, vendored in `tests/contract-fixture/` from langsys-js-typescript at tree `542f57f5` and run by `tests/Contract/`. Counts live only in *Computed summary*, which is produced by a script, not typed.

**A `delegated` row is only as good as the core row it cites, and it is graded that way.** Its tier is `-`, because the behaviour's tier lives on the core row, and the summary resolves each delegated row against the core's current grade, as the fleet checker does. At `9ff7b35` no core row this package delegates to is `not implemented`; MIG-9 is `provisional` there, waiting on the shared double learning the translations map.

**Three changes graded here are pending the operator's ruling:** the removal of `auto_flush`, the removal of the `TranslateResponse` page cache, and the lowercase Inertia locale. The rows grade the code at this tip, and each row that rests on one of them says so. *Pending the operator's ruling* lists what each row becomes if a change is reverted.

Test paths are under `tests/`. In an evidence cell, `::name` continues the last file named in that cell. `M`, `T`, `F`, `P`, `L`, `C`, `V`, `S`, `K`, `R`, `A`, `E`, `N`, `B`, `G`, `W`, `X`, `Z`, `D`, `Q` and `Y` numbers refer to *Mutation record*.

## What surfaced while writing this

Found by executing code against the rules — a probe, a real-core test, a contract test or a mutant.

- **Laravel's `setLocale()` rewrites `app.locale`.** So "the locale is still `app.locale`" cannot tell whether the application resolved the locale. SRV-6's "nothing resolved it" is Laravel's `LocaleUpdated` event having fired this request, which also counts an application that sets its default explicitly.
- **The escaping `@lang` hides every call from the source scanner.** Once `@lang` and `@t` compile to the escaping call (FRM-8), the core's scanner no longer sees them as translate calls. `langsys:sync` compiles views for reading with a plain Blade compiler, where both compile to Laravel's own `app('translator')->get()`.
- **Compiled Blade lines are not the view's.** Blade drops comments and rewrites directives, so `langsys:sync` gives each hit the line of its own call in the view: the n-th call of a function in the compiled view is its n-th in the source.
- **`Password` implements Laravel's `Rule` contract but has no message before it fails.** A rule object whose `message()` is empty ahead of time is reported, not silently dropped.
- **laravel-data resolves an optional nested object's rules only when the payload carries it.** The listing builds a sample payload from the DTO's own properties.
- **Laravel has no hook before a `Mailable` renders.** A notification is recognised while it is sent, through Laravel's own events; a Mailable sent synchronously while serving an Inertia page answers as the page does (FRM-4).

## Status

| Rule | Status | Tier | Evidence |
|---|---|---|---|
| GATE-1 | delegated | - | Core `GATE-1`: implemented. The binding never reads `write_enabled` or `key_type` and never calls `canWrite()` on any render path: `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`, firing control `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`, M16a. The listing command's `--register` reaches `canWrite()` only through the core's `MessageCatalogCommand::register()`. |
| GATE-2 | n/a (architecture: synchronous core — the write decision resolves in-line at the send site, so no unknown window exists to hold a phrase through; live if the core became asynchronous) | - | The spec records synchronous SDKs `n/a` here. The residual obligation is REG-10, delegated below. |
| GATE-3 | implemented | n/a (pure) | Octane workers and queue workers keep the `Client` singleton across units of work, so the provider flushes and then calls `resetRequestState()` at every boundary: queue job processed, queue job threw, and Octane `RequestTerminated`. `RequestScopeTest::testTheNextUnitOfWorkStartsWithoutAWriteDecision` sets a decision, crosses each boundary and asserts the next unit starts without one. `::testEveryBoundaryFlushesAndThenResets` pins the flush before the reset, and `::testABoundaryNeverBuildsAClientNobodyUsed` covers an unused client. M1, M2, M3, M4, M5. The Octane leg dispatches a stand-in `Laravel\Octane\Events\RequestTerminated`: Octane is not installed, and the provider listens by class name. Keeping the decision out of any shared cache is the core's half, core `GATE-3`: implemented. PHP-FPM needs no reset, because the process ends the scope. |
| GATE-4 | delegated | - | Core `GATE-4`: implemented. The binding writes no cache entry of its own — `TranslateResponseTest::testEveryRequestReachesTheSdk`, M10 (the page cache's removal is pending the operator's ruling) — and `LaravelCacheAdapter` stores exactly what the core hands it, `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. It never names the flag it would have to strip or add: capability scan, M16a. |
| GATE-5 | delegated | - | Core `GATE-5`: implemented. The binding keeps no registration bookkeeping and never reads a flush result; `flushPendingRegistrations()` is its only call on the write lane. `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `queuePhraseForRegistration`, `createPhrases`, `registerPhrases` and `clearPendingRegistrations`; firing control `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`; M16c. |
| GATE-6 | delegated | - | Core `GATE-6`: implemented. The binding has no hint lane: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `discovery/hint` and all transport. It makes no write decision either, `::testTheBindingNeverTouchesServerComputedCapability`. |
| GATE-7 | delegated | - | Core `GATE-7`: implemented. Every Laravel route that can meet unregistered content reaches a core entry point that feeds the register lane: `php artisan langsys:sync` registers what `__()`, `@lang` and `t()` can show and every validation message (FRM-2, MSG-7); `TranslateResponse` hands the page to `translatePage()`, whose walk collects what it meets; a declared value added since the last sync registers through the core's FRM-7 exception. `ServerRenderContractTest::testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse` reads the page walk's registration back from the double; `Contract/SyncContractTest::testEveryLiteralCallAndBaseLanguageLineIsRegistered` reads sync's. Pass-through probes: `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` and `TranslateResponseTest::testServesExactlyWhatTheSdkReturned`. |
| GATE-8 | delegated | - | Core `GATE-8`: implemented. The binding consults neither the flag nor the key type: `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`, M16a. |
| GATE-9 | n/a (profile: browser) | - | The discovery gate governs a client's `t()`. This package runs on the server and sends no discovery. |
| GATE-10 | implemented | contract | **Producing is the half that binds a server.** A page Laravel renders in a locale other than the project's base holds text `__()` translated with no page walk, so `MarkResolvedPage`, on the `web` group, has the core mark its root (`Client::markResolved()`, string-level): `ResolvedMarkerTest::testAPageRenderedInANonBaseLocaleIsMarkedResolved` asserts the marker and nothing else changed, `::testAPageInTheBaseLocaleStaysSource`, `::testAnInertiaPageIsNotMarked`, and a request that built no client is left alone, `::testAPageThatTranslatedNothingIsLeftAlone`. Against the double, the base locale the server reports decides: `Contract/ServerRenderContractTest::testABladePageServesTheCatalogAndRegistersNothing`. A page `TranslateResponse` walks is marked by the core's `translatePage()` (core `GATE-10`: implemented). Z6, Z7, Z8. Reading is a DOM host's, and this package has none. |
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
| ICU-1 | delegated | - | Core `ICU-1`: implemented. Interpolation is the core's. The binding hands `$params` over raw, `LangsysTranslatorTest::testPassesParamsThroughToTheSdkSoTheRawPhraseIsRegistered`, and applies nothing to the result, `::testReturnsExactlyWhatTheSdkReturned`, M7a. `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `Interpolator` and `MessageFormatter`. A `trans_choice()` count reaches the core's ICU as the `count` param, `Translation/CatalogTranslatorTest::testATranslatedPluralSelectsForTheRequestLocale`, T3. |
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
| VAR-1 | implemented | n/a (pure) | A value passed to `__()` travels as a param and fills its `{name}` placeholder after lookup, so one sentence is one phrase for every user: `Translation/CatalogTranslatorTest::testASentenceNoFileHoldsIsConverted`, and the same for a key's line, `::testAGroupKeyRendersTheCatalogTranslationOfItsSourceLine`. A value from a declared set is a word to translate, written in (FRM-7). A page with no markers that `TranslateResponse` walks registers the text it renders, as VAR-7 documents, until VAR-5's emitter ships. |
| VAR-2 | n/a (architecture: this package derives no placeholder name — Laravel's translate function takes the app's own names, and there is no marker emitter yet; live with VAR-5's Blade emitter) | - | The naming rules and the shared vectors bind the emitter, which waits on VAR-4. |
| VAR-3 | delegated | - | Core `VAR-3`: implemented; the page walk and `translateRich()` read both marker forms. The binding reads and emits no marker: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `data-ls-` in code. |
| VAR-4 | n/a (architecture: this package emits no marker; live when VAR-5's Blade emitter ships, which cites the reader rows it waits on) | - | The Blade emitter is held until the PHP and TypeScript readers are released. |
| VAR-5 | not implemented | - | The Blade emitter — a precompiler marking every value printed in visible text — is measured and not built: VAR-4 holds it until every reader that can meet its output is released. A page Laravel renders registers nothing at runtime (FRM-2), so an unmarked value reaches the catalog only through the page walk. Gap 1. |
| VAR-6 | n/a (architecture: Blade renders on the server and has no component compile step; a server template is marked at render, VAR-5) | - | Build-time transforms belong to the JS component bindings. |
| VAR-7 | delegated | - | Core `VAR-7`: implemented. `langsys:sync` registers nothing for a call it cannot read as a literal, and reports it, `Contract/SyncContractTest::testANonLiteralCallIsReportedWithItsFileAndLineAndFailsStrict`. |
| SSR-1 | n/a (profile: browser) | - | Governs the browser SDK's strategy under server rendering. |
| SSR-2 | n/a (profile: browser) | - | As SSR-1. |
| SSR-3 | n/a (profile: browser) | - | As SSR-1. |
| SRV-1 | implemented | contract | On the route an application renders, against the contract fixture: a Blade page served with `?locale=es-ES` carries the catalog's translation and the miss in the base language, `Contract/ServerRenderContractTest::testABladePageServesTheCatalogAndRegistersNothing`, and so does the page walk, `::testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse`. `ServedBytesTest::testTheServedBytesCarryTheRequestLocalesTranslations` pins the served bytes on the real core. M19. The sanctioned exception for a block a binding cannot show the renderer has no site here: Blade renders eagerly on the server, and a content block reaches the core only through the page walk. |
| SRV-2 | implemented | n/a (pure) | PHP-FPM builds a container per request; Octane and queue workers do not, so the provider resets the core's per-request state at each boundary. `RequestScopeTest::testTheNextUnitOfWorkReadsTheCatalogAfresh` reads a catalog, moves the shared cache on, crosses each of the three boundaries and asserts the next unit reads the new catalog; M1, M4 and M5 each redden it. The rule's interleave cannot occur in these runtimes: a PHP-FPM process and an Octane worker each serve one request at a time, so concurrent requests never share a `Client`, and the failure that exists is sequential reuse across a boundary, which GATE-3 names. It becomes live with in-worker concurrency sharing the singleton, such as Swoole coroutine hooks. The catalog memo is the core's, core `SRV-2`: implemented. |
| SRV-3 | implemented | contract | Registration never spends the visitor's latency: `__()` registers nothing while serving a request (FRM-2), and the page walk's misses are sent after the response, `Contract/ServerRenderContractTest::testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse`, with the order of events pinned by `FlushPendingRegistrationsTest::testTheFlushRunsOnlyOnceTheResponseExists`, M8. A key that may not write registers nothing even when the world changes after it learned so — the double drifted to accept the write — with the positive control in the drifted world: `Contract/ServerRenderContractTest::testAKeyThatMayNotWriteRegistersNothingEvenWhenTheWorldChanges`, X1. |
| SRV-4 | implemented | n/a (pure) | **This package holds the server's half, and names the two halves it does not.** Held: hand the client the catalog the server rendered with. `InertiaSsrProps::share()` reads the same `getTranslations()` memo that `t()` read. `InertiaSsrPropsTest::testHandsTheClientTheCatalogThisRequestRenderedWith` renders `@t` on the real core, moves the shared cache on, and still receives the rendered catalog; M14 re-reading the cache reddens it. The locale goes over in the form both SDKs identify it by, its casing pending the operator's ruling: `::testEveryHostSpellingHandsTheSdkForm`, M15. An outage hands no seed instead of a 500, `::testAnUnreachableApiHandsNoSeedInsteadOfThrowing`, M13. Not held: the synchronous seed, which is langsys-js-typescript's, and calling it before hydration, which belongs to the JS framework binding under Inertia. A page `TranslateResponse` translates is terminal HTML with no hand-off. |
| SRV-5 | delegated | - | Core `SRV-5`: implemented. Once per subtree, on this package's page route: `ServedBytesTest::testAMissTheWalkMeetsManyTimesIsQueuedOnce` has the page walk meet one miss eight times across nested elements on the real core, and counts one registration. The binding cannot add a registration of its own: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `queuePhraseForRegistration`, M16c. Blade renders eagerly on the server, so the binding captures no placeholder and declines no subtree. |
| SRV-6 | implemented | contract | **Laravel's locale is served.** Where anything set it this request — the provider marks the request on Laravel's `LocaleUpdated` — the core maps it for the client and nothing is varied: `DetectLocaleTest::testALocaleTheAppResolvedIsServedAsItIs`, `::testTheAppSettingItsDefaultLocaleCountsAsResolved`, `::testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `::testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase`. L1, L2, L3, L4. **Where nothing set it**, `DetectLocale` reads the app's `sources` in order, validates each candidate against the project's locales narrowed by `supported`, and adds the `Vary` its choice depended on: `::testTheSourcesAreAskedInTheConfiguredOrder`, `::testTheSupportedListNarrowsTheProjectsLocales`, `::testTheHeaderIsNegotiatedAndTheResponseVariesOnIt`, L5 to L10. Against the double, the locales are the ones the server reports: `Contract/ServerRenderContractTest::testTheRequestLocaleIsValidatedAgainstTheProjectsLocales`, X2, X3. The core never sends a raw `Vary`: `ServiceProviderTest::testTheClientNeverSendsVaryItself`, L14. |
| SRV-7 | n/a (architecture: the request scope is the browser core's seam for JS bindings that render on a server; this package wraps the PHP server core, whose per-request state lives on a Client reset at every boundary, GATE-3 and SRV-2; live if this package rendered through a browser core) | - | The binding builds no request state of its own: the core's `Client` holds the locale, the catalog memo and the queue, and `RequestScopeTest` pins their reset at every boundary. |
| MSG-1 | implemented | n/a (pure) | Laravel's error body is untouched and the entries sit beside it under `langsys.messages.response_key`: `Messages/ResponseEnvelopeTest::testTheJsonBodyKeepsLaravelsShapeAndCarriesTheEntriesBesideIt`, `::testTheKeyIsConfigurable`, `::testAnOrdinaryResponseIsUntouched`. The pieces' names are configurable, `::testThePieceNamesAreConfigurable`, E1. A redirecting form carries them in the session, `::testARedirectingFormCarriesTheEntriesInTheSession`. The entry object is the core's, core `MSG-1`: implemented. |
| MSG-2 | implemented | n/a (pure) | An entry's code is Laravel's own rule name, whatever the field's type: `Messages/ValidatorMessagesTest::testTheCodeIsLaravelsRuleName`, C1. A rule object or closure carries the class Laravel records it under, `::testARuleObjectOrClosureCarriesTheClassLaravelRecords`, C2. A failure with no rule carries no code, `::testATextOnlyFailureCarriesNoCode`, V1. The sentences are Laravel's own, read from the installed framework's `validation.php`. |
| MSG-3 | implemented | n/a (pure) | A template is Laravel's sentence in the source language with the label written in and non-translatable values as `{name}` markers: `Messages/ValidatorMessagesTest::testLabelsAreWrittenInAndNoPlaceholderSurvives`, `::testValuesStayOutsideThePhraseAsMarkers`, `::testTheSentenceIsReadInTheSourceLanguageNotTheRequestLocale`; filling it reproduces Laravel's own message byte for byte, `::testAFilledTemplateReproducesLaravelsOwnMessage`. Which placeholder is a label, a written-in value or a marker is classified for every rule the installed Laravel ships: `Messages/RuleWordingTest::testEveryRuleLaravelShipsIsClassified`, `::testEveryPlaceholderInEachLineIsClassified`, `::testLabelsAreNeverMarkers`. |
| MSG-4 | delegated | - | Core `MSG-4`: implemented. Every entry is built with the core's `ServerMessage::make()`, which fills `message`; a number stays a number, `Messages/ValidatorMessagesTest::testValuesStayOutsideThePhraseAsMarkers`. |
| MSG-5 | n/a (architecture: this package sends entries and renders none — Laravel's message bag and 422 `errors` keep Laravel's source-language text, and a client SDK renders each entry; live when server-rendered pages print translated errors, which awaits the operator's ruling) | - | `Messages/MigrateModeTest::testTheServerNeverEmitsTranslatedText`. A Blade-only page shows validation errors in the source language: Gap 2. The helper a server render would use is the core's `Client::translateMessage()`. |
| MSG-6 | delegated | - | Core `MSG-6`: implemented. `langsys.messages.category` is the core's `messages_category`, `Messages/MigrateModeTest::testTheConfiguredCategoryIsTheCoresMessagesCategory`; a JSON entry renders from the template the double holds under `Errors`, `Contract/NegotiatedMessageContractTest::testTheMessageIsInTheLanguageTheServerServes`. |
| MSG-7 | implemented | contract | `php artisan langsys:sync` is this command (FRM-2): it registers every validation message the app can send beside every phrase, and `langsys:messages` lists them alone. It lists every rule of every FormRequest and laravel-data request DTO a route action takes, each field's label written in: `Messages/MessagesCommandTest::testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `::testALaravelDataRequestIsListedLikeAFormRequest`, `::testACustomMessageIsListedAsTheAppWroteIt`; a rule object from its `template()` once per field, `::testARuleObjectWithATemplateIsListedWithItsMarkers`, and one without it from its filled message, named with the interface, `::testARuleObjectWithAMessageIsListedForEachField`, `::testARuleObjectWithoutATemplateIsNamedWithTheInterface`. What a request sends is what was listed, a templated rule object included, `::testWhatARequestSendsIsWhatWasListed`, R1, R3, R5. What cannot be listed is named with its fix and fails only `--strict`, `::testWhatCannotBeListedIsNamedWithItsFix`, `::testTheCommandReportsByDefaultAndFailsUnderStrict`. Registration is read back from the double, `Contract/SyncContractTest::testEveryLiteralCallAndBaseLanguageLineIsRegistered`, and a second run registers nothing new, `::testASecondSyncRegistersNothingNew`. Laravel's own rule objects are listed from Laravel's line, `Rules\Enum` with the validator it reads through, `Messages/MessagesCommandTest::testLaravelsOwnRuleObjectsAreListedFromLaravelsLine`, Y1, Y2; a field that throws is reported and the listing carries on, `::testARuleThatThrowsIsReportedAndTheListingContinues`, Y3. S1 to S9, K1 to K6, D1 to D4. |
| MSG-8 | delegated | - | Core `MSG-8`: implemented. Under FRM this rule is FRM-7's runtime exception alone: a sentence built from a synced template and a declared value the last sync did not see registers after the response, `ValueSetsTest::testAValueAddedSinceTheLastSyncRegistersItsSentence`; every other failure registers nothing at runtime, `Messages/MigrateModeTest::testAFailureRegistersNothingAtRuntime`, Z1. |
| MSG-9 | implemented | n/a (pure) | Each entry is built from the rule that failed and its parameters — Laravel's unfilled line, read through Laravel's own `getMessage()` and replacer — never from the rendered bag: `Messages/ValidatorMessagesTest::testOneEntryPerFailedRule`, `::testAFilledTemplateReproducesLaravelsOwnMessage`. A rule object implementing the core's `HasMessageTemplate` is sent from its template and public properties, not the text it filled, `::testARuleObjectWithATemplateIsSentFromIt`, R3, R5. A failure that arrives as text only registers as that text with no params, `::testATextOnlyFailureCarriesNoCode`, and the listing names text-only messages it meets. |
| MSG-10 | implemented | n/a (pure) | The label is the one Laravel prints: `attributes()` — a FormRequest's, or a laravel-data DTO's — where declared, else Laravel's derived name, both through Laravel's `getDisplayableAttribute()`: `Messages/ValidatorMessagesTest::testLabelsAreWrittenInAndNoPlaceholderSurvives`, `Messages/MessagesCommandTest::testALaravelDataRequestIsListedLikeAFormRequest`. The listing names a field with no declared label, with the name Laravel prints instead, as advice through the core's `MessageCatalog::advise()`: `::testAFieldWithNoDeclaredLabelIsNamed`, A1; it never fails the build, `--strict` included, `::testAFieldWithNoDeclaredLabelNeverFailsTheBuild`, A2, A3. |
| MSG-11 | delegated | - | Core `MSG-11`: implemented. The listing adds every template through the core's `MessageCatalog::add()`, which refuses a Laravel label placeholder and any placeholder left unfilled: `Messages/MessagesCommandTest::testWhatCannotBeListedIsNamedWithItsFix` meets the refusal for `:thing`. The fill-time warning is the core's, at `emitMessage()`. |
| MSG-12 | implemented | n/a (pure) | A failed form that redirects hands its entries to the next page as an Inertia prop, beside Laravel's untouched `errors`: `Messages/InertiaHandoffTest::testTheEntriesReachTheNextPageAsAProp`, `::testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation`, and a page after no failure carries nothing, `::testAPageWithNoFailureCarriesNothing`. Inertia is a development dependency only. |
| MIG-1 | n/a (architecture: an FRM SDK has no mode — installing it is the configuration, and `langsys.enabled` turns all of it off; live if this package offered migration as an opt-in mode) | - | Installed, the core is given Laravel's own base-language files, `EnabledByDefaultTest::testInstallingIsEnough`; switched off, none, and no key is looked up, `DisabledTest::testTheClientIsBuiltWithNoMigrationFiles`, `::testLaravelsOwnTranslatorAnswers`, P1, P2. |
| MIG-2 | implemented | n/a (pure) | Key first: a key a file holds renders its line's translation, `Translation/CatalogTranslatorTest::testAGroupKeyRendersTheCatalogTranslationOfItsSourceLine`. Any other argument is literal text, converted by the Laravel call that received it through the core's `LegacyValue::fromCall()`, and looked up as converted: `::testASentenceNoFileHoldsIsConverted`, `::testASentenceKeepsWhatLaravelWouldPrintAsWritten`, `::testAPipePluralNoFileHoldsIsConvertedToo`, `::testACapitalisingPlaceholderIsLookedUpAsWrittenAndWarned`. The same rule decides what `langsys:sync` registers. T2, T8, T9, T10. Core `MIG-2`: implemented. |
| MIG-3 | implemented | n/a (pure) | The phrase looked up and synced through a key is its source line, never the key: a catalog entry under the key itself is never served, `Translation/CatalogTranslatorTest::testTheKeyIsNeverThePhrase`, and a JSON key's line is the phrase, `::testAJsonKeyResolvesToItsLine`; sync registers `Welcome back, {name}` under `messages` for `__('messages.welcome')`, `Contract/SyncContractTest::testEveryLiteralCallAndBaseLanguageLineIsRegistered`. Core `MIG-3`: implemented. |
| MIG-4 | delegated | - | Core `MIG-4`: implemented. Conversion is the core's; through `__()` a Laravel plural becomes one ICU plural over `count`, `Translation/CatalogTranslatorTest::testAPipePluralRendersThroughIcuOverCount`, `::testAJsonPluralRendersThroughIcuOverCount`. |
| MIG-5 | delegated | - | Core `MIG-5`: implemented. The binding passes no category, so the group is the category: `Contract/SyncContractTest::testEveryLiteralCallAndBaseLanguageLineIsRegistered` reads `Welcome back, {name}` back under `messages`, and a framework line resolves under its own group, `Translation/CatalogTranslatorTest::testTheFrameworksOwnLinesAnswerWhatTheAppDoesNotDefine`. |
| MIG-6 | delegated | - | Core `MIG-6`: implemented. The binding treats no drift of its own; a key no file holds reaches the core as literal text. |
| MIG-7 | implemented | n/a (pure) | The core is given the files Laravel's own loader reads in the source locale, in Laravel's order and tiers: the app's JSON (declared `laravel`) then its groups; the framework's bundled English and package JSON as fallback; a package's `lang/vendor` override ahead of its own files; `validation.php` in no tier. `Translation/CatalogTranslatorTest::testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale`, `::testValidationLinesAreNotMigrationSourceFiles`, `::testAPackageKeyResolvesThroughItsNamespaceWithTheAppsOverride`, F1 to F8. Core `MIG-7`: implemented. |
| MIG-8 | implemented | n/a (pure) | Installed, Laravel's `translator` is this package's subclass: `__()`, `trans()`, `@lang` and `trans_choice()` go to the core's `resolve()` with no call site changed. Validation keys stay with Laravel, `Translation/CatalogTranslatorTest::testValidationKeysStayWithLaravel`, T1, T7; `has()` asks the core whether a file holds the key, `::testHasAnswersWhetherAFileHoldsTheKey`, T5; the locale is the request's, normalized, `::testAnExplicitLocaleIsNormalizedBeforeTheLookup`, T6; resolving the translator builds no client, `::testResolvingTheTranslatorDoesNotBuildTheClient`, P3. |
| MIG-9 | delegated | - | Core `MIG-9`: provisional, waiting on the shared double learning the translations map. `langsys:sync` carries the import: a base-language line the other locales' files translate registers with those translations in one call, `Contract/SyncContractTest::testTheCountsNameWhatTheLangFilesAlreadyTranslate`, Z15. The binding sends nothing of its own: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| FRM-1 | implemented | n/a (pure) | Laravel's own translate function is the SDK's: installed, Laravel's `translator` is `CatalogTranslator`, and an unchanged `__()` returns the catalog's translation, `EnabledByDefaultTest::testInstallingIsEnough`, `Translation/CatalogTranslatorTest::testAGroupKeyRendersTheCatalogTranslationOfItsSourceLine`. `t()` is `__()` by another name, `DisabledTest::testLaravelsOwnTranslatorAnswers`. The single off switch, `langsys.enabled`, leaves plain Laravel: `DisabledTest`. P2. **Installing never breaks the app:** with no key, every literal, key-style and plural call returns exactly plain Laravel's output, `@lang` included, and no client is built, `NoCredentialsTest::testEveryCallReturnsPlainLaravelsOutput`; a page through `langsys.locale`, the page walk and `langsys.flush`, and a failed JSON form, still serve with nothing reported, `::testAPageAndAFailedFormStillServe`; `langsys:sync` says what it needs, `::testSyncSaysWhatItNeeds`. Q1 to Q4, Q6 to Q8. |
| FRM-2 | implemented | contract | `php artisan langsys:sync` registers every literal `__()`, `trans()`, `trans_choice()`, `@lang` and `t()` in PHP and Blade and every base-language line, over the core's scanner and plan: `Contract/SyncContractTest::testEveryLiteralCallAndBaseLanguageLineIsRegistered`; a phrase in the catalog registers nothing, `::testASecondSyncRegistersNothingNew`; a line the lang files translate registers with its translations, `::testTheCountsNameWhatTheLangFilesAlreadyTranslate`; a non-literal call is reported with its file and line and fails `--strict`, `::testANonLiteralCallIsReportedWithItsFileAndLineAndFailsStrict`; a literal holding `:attribute` registers nothing on its own and is reported as registered through the validation listing, `::testALineHoldingALabelPlaceholderRegistersOnlyThroughTheValidationListing`, S10, the filter being the core's `planSync()`; `--watch` syncs again on a change, `::testWatchSyncsAgainWhenAFileChanges`. Serving a request registers nothing: `Translation/FallbackAndEscapingTest::testNothingRegistersAtRuntime`, and against the double `Contract/ServerRenderContractTest::testABladePageServesTheCatalogAndRegistersNothing`. A rule object implementing the core's `HasMessageTemplate` is listed with its `{max}` marker, not the number, `Messages/MessagesCommandTest::testARuleObjectWithATemplateIsListedWithItsMarkers`, and one without it is named with the interface, which fails `--strict`, `::testARuleObjectWithoutATemplateIsNamedWithTheInterface`. Z1, Z12 to Z17, R2, R4. A key built at runtime inside a literal group is listed as covered and fails nothing — a group the lang files hold, and `validation`, which the binding names to the core's plan because its lines are the validator's and never a migration file — while one with no literal group is still reported, `Contract/SyncContractTest::testARuntimeKeyInsideALiteralGroupIsCoveredByTheGroup`, S11, S12. |
| FRM-3 | implemented | n/a (pure) | `__()` returns the catalog's translation, else the app's own lang-file translation for the locale being rendered, else the source filled: `Translation/FallbackAndEscapingTest::testTheCatalogWinsOverTheLangFiles`, `::testALangFileTranslationAnswersWhatTheCatalogLacks`, `::testWithNeitherTheSourceIsReturnedFilled`. The chain is the core's (`useMissFallback()`); Laravel's own translator answers the fallback, its line converted as the source is. Z2, Z5. **The chain never throws:** an API that cannot be reached is a catalog with nothing in it, through the real core, `NoCredentialsTest::testAnUnreachableApiIsPlainLaravelToo`; with no key the translator never builds a client (`Support\ClientState`), and the cause is reported once per process at debug, `::testTheCauseIsReportedOncePerProcessAtDebug`, Q5. A catalog load that fails is the core's to absorb (core `WIRE-4`). |
| FRM-4 | partial | - | **Met:** a page the server renders gets the translation and its root is marked resolved, an Inertia page gets the source, the route-group override wins, and a notification is translated in the recipient's `preferredLocale()`, from an Inertia page and from a queued job: `ResponseKindTest`, `ResolvedMarkerTest`, `Translation/CatalogTranslatorTest::testANotificationIsTranslatedInTheRecipientsPreferredLocale`. W1 to W6, Z6 to Z8. **Not met:** a `Mailable` sent synchronously while serving an Inertia page answers as the page does, with the source — Laravel offers no hook before a Mailable renders. Queued mail is translated. Gap 2. |
| FRM-5 | implemented | contract | A JSON validation failure's entries carry `message` in the language negotiated against the locales the server says the project serves, the template and params beside it, `Content-Language` and `Vary`: `Contract/NegotiatedMessageContractTest::testTheMessageIsInTheLanguageTheServerServes`, `::testAnUnservedLanguageGetsTheSourceInTheBaseLocale`. A locale the app resolved is served as it is, with no `Vary`, and Laravel's own `message` and `errors` are untouched: `Messages/NegotiatedMessageTest`. G1 to G5. |
| FRM-6 | n/a (profile: browser) | - | A browser SDK's header helper. |
| FRM-7 | implemented | n/a (pure) | Declarations are found in app/ without configuration — a backed enum marked `#[TranslatesAs]`, a class implementing `TranslatableValues` — and `langsys.value_sets` adds classes kept elsewhere: `ValueSetsTest::testDeclarationsAreFoundWithoutConfigurationAndAddedFromConfig`. A declared value looks up its written-in sentence, `::testADeclaredValueLooksUpTheSentenceWithTheWordWrittenIn`, `::testWithoutALabelTheValueIsTheWord`; an undeclared one stays a placeholder, `::testAnUndeclaredValueStaysAPlaceholder`; a value added since the last sync registers its sentence, `::testAValueAddedSinceTheLastSyncRegistersItsSentence`. `langsys:cache` caches discovery, `::testTheCacheIsWrittenAndRead`. Z9, Z10, Z11. Enumeration at sync is the core's `planSync()`, core `FRM-7`: implemented. |
| FRM-8 | implemented | n/a (pure) | `@lang` and `@t` print through the core's `translateRich()`: catalog `<script>` is escaped through both, `Translation/FallbackAndEscapingTest::testCatalogMarkupIsEscapedThroughLangAndT`; the app's own lang-file `<a href>` prints raw, `::testTheAppsOwnLangFileMarkupPrintsAsLaravelPrintsIt`; a rich source line is rebuilt around the translated run, `::testARichSourceLineIsRebuiltFromTheSourcesElements`; a translator-added tag is escaped and an unknown token dropped, `::testATranslatorAddedTagIsEscapedAndAnUnknownTokenDropped`; the block form stays Laravel's and `{!! __() !!}` stays the developer's raw choice. Z3, Z4. |
| SNAP-1 | delegated | - | Core `SNAP-1`: implemented; the export is the core's `vendor/bin/langsys-snapshot`. The binding exports nothing and adds no endpoint: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| SNAP-2 | implemented | n/a (pure) | `langsys.snapshot` names a snapshot file the provider hands the core's `snapshot` option, so a lookup answers with no network: `SnapshotTest::testAConfiguredSnapshotAnswersWithNoNetwork`, with its control `::testWithoutASnapshotTheLookupFallsBackToSource`, N1. Lookups, precedence and the offline served set are the core's, core `SNAP-2`: implemented. |
| SNAP-3 | delegated | - | Core `SNAP-3`: implemented; `Snapshot::load()` refuses an edited snapshot. The binding reports the refusal and builds the client without it, never failing a request: `SnapshotTest::testASnapshotTheCoreRefusesIsReportedAndSkipped`, N2, N3. |
| BIND-1 | implemented | n/a (pure) | **Shape and timing only.** Timing: `terminate()` and the long-lived boundaries decide *when* the core flushes and resets. Shape: the Laravel cache adapter, the casing of Laravel's own locale store, the Inertia prop shape, route scoping for `TranslateResponse`, Laravel's translator and validator hooks, whether a client can be built at all (`ClientState`: the core's constructor refuses to build one without credentials, and with none the catalog is empty, so FRM-3's chain is Laravel's own answer), the entries' place and piece names in Laravel's error body, and the file list the migration mode reads. Everything that is meaning is delegated and proven absent by the three scans in `BindingBoundaryTest`, each with firing control `BindingBoundaryTest::testEveryScanFiresOnAPlantedViolationAndNotOnAComment` and coverage control `::testTheScanReadsEverySourceFile`. Pass-through probes cover both render routes: `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` and `TranslateResponseTest::testServesExactlyWhatTheSdkReturned`. M7a, M7b, M7c, M9, M10, M16b. |
| BIND-2 | implemented | n/a (pure) | `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability` finds no `write_enabled`, `key_type`, `canWrite`, `auto_discovery` or `ip_write` in code. Firing control: `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`. M16a plants `canWrite()` and reddens the scan. |
| BIND-3 | implemented | n/a (pure) | `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` finds no transport, auth or grant header, hint endpoint, batching, registration construction or timer. Firing control: `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`. M16c plants `usleep()` and reddens the scan. Flushing at a lifecycle boundary is timing under BIND-1, not scheduling. `DetectLocale`'s `Set-Cookie` and `Vary` are headers on the application's own response, not on a request to Langsys. |
| BIND-4 | implemented | n/a (pure) | `BindingBoundaryTest::testEveryConfigKeyIsACoreOptionOrLaravelWiring` pins every key in `config/langsys.php` with its classification: a core option mapped onto Laravel, SRV-6 wiring (where Laravel keeps a locale value, which sources it reads and how it narrows the project's locales), or whether and where Laravel invokes the core. Firing control: `::testTheConfigCheckSeesAnAddedKey`. M17 re-adding `auto_flush` reddens the pin. `auto_flush` is removed, pending the operator's ruling: it was product configuration the core does not define, and under PHP-FPM it never stopped a flush. |
| BIND-5 | implemented | n/a (pure) | `TranslateResponseTest::testEveryRequestReachesTheSdk`: two identical requests both reach `translatePage()`, and M10 memoizing the page reddens the test. The page cache is removed, pending the operator's ruling. `LaravelCacheAdapter` is the core's own cache mapped onto a Laravel store, not a binding cache, and key presence survives it: `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. |
| BIND-6 | implemented | n/a (pure) | `BindingBoundaryTest::testThePublicSurfaceIsTheOneArguedForHere` pins every public method each class declares, plus `t()`; M18 adding one reddens it. `Langsys::client()` re-exports the core `Client` by reference, `FacadeTest::testClientReturnsTheContainersSdkClient`. Every new name is a framework idiom: middleware `handle` and `terminate`, Inertia's `share`, an Artisan command's `handle`, Laravel's translator methods (`get`, `choice`, `has`), a cache-store adapter, a validator subclass, the core's `MessageSource` for Laravel's FormRequests, `LocaleFormatter::canonicalize` for Laravel's own locale store, and `ClientState::buildable`, the one question every runtime site asks before it builds a client. |
| GRANT-1 | n/a (profile: browser) | - | A server binding holds a write key, and a server SDK must not send `X-Write-Grant`. This package sets no request header of any kind: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `x-write-grant`. |
| GRANT-2 | n/a (profile: browser) | - | As GRANT-1. |
| GRANT-3 | n/a (profile: browser) | - | As GRANT-1. |
| GRANT-4 | n/a (profile: browser) | - | As GRANT-1. |
| CACHE-1 | implemented | n/a (pure) | The keys this package writes itself are scoped to the project: `LaravelCacheAdapter`'s key index carries the project id, so clearing one project's cache leaves another's: `LaravelCacheAdapterTest::testClearingOneProjectLeavesAnotherProjectsKeys`, and on the route an application takes, `ServiceProviderTest::testTheProviderScopesTheCacheIndexToTheProject`. M11. The core's own keys pass through verbatim with the core's namespacing, core `CACHE-1`: implemented. An adapter constructed by hand without `$projectId` keeps an unscoped index; the service provider never builds one that way. |
| CACHE-2 | delegated | - | Core `CACHE-2`: implemented; the failure window lives on the `Client` and survives `resetRequestState()`, so it carries across Octane requests. The binding fetches nothing itself: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| OBS-1 | delegated | - | Core `OBS-1`: implemented. Emitting it needs the capability the binding may not read (BIND-2): `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`. |
| WIRE-1 | delegated | - | Core `WIRE-1`: implemented. The binding sets no request header: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `x-authorization` and transport. |
| WIRE-2 | delegated | - | Core `WIRE-2`: implemented. The binding parses no response: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| WIRE-3 | implemented | n/a (pure) | Every locale this package hands the core is the lowercase `xx-yy` both SDKs identify a locale by. `LangsysTranslator` normalizes before `resolve()`, `translateRich()` and `translate()`, whose catalogs are keyed by the locale they are handed: `LangsysTranslatorTest::testEveryHostSpellingOfALocaleReadsTheSameCatalog` reads one catalog entry from `es-es`, `es-ES`, `es_ES` and `ES-es` on the real core, M6. `__()` goes through the same path, `Translation/CatalogTranslatorTest::testAnExplicitLocaleIsNormalizedBeforeTheLookup`, T6. The Inertia hand-off sends `es-es`, a casing pending the operator's ruling: `InertiaSsrPropsTest::testEveryHostSpellingHandsTheSdkForm`, M15. `DetectLocale` maps a framework locale through the core, SRV-6. Canonical BCP 47 remains only in Laravel's own `app()->getLocale()`, the host locale store this rule describes. For categories, the binding passes `null` and never spells `__uncategorized__`, M7c. The wire is the core's, core `WIRE-3`: implemented. |
| WIRE-4 | implemented | n/a (pure) | Proven on the real core with the API genuinely unreachable — a refused connection to a closed local port — on every entry point this package exposes. `t()` renders the interpolated source phrase and queues nothing, `LangsysTranslatorTest::testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing`, M21. `TranslateResponse` serves the untranslated page, `TranslateResponseTest::testAnUnreachableApiServesTheUntranslatedPage`. A boundary flush fails nothing, `RequestScopeTest::testAFlushThatCannotReachTheApiFailsNothing`. `InertiaSsrProps::share()` hands no seed instead of a 500, `InertiaSsrPropsTest::testAnUnreachableApiHandsNoSeedInsteadOfThrowing`, M13. `DetectLocale` leaves Laravel's locale alone when the project's locales cannot be read, `DetectLocaleTest::testAnUnreachableProjectLeavesTheAppLocaleAlone`, L12. A validation failure with a failing client keeps Laravel's messages, `Messages/MigrateModeTest::testAFailingClientLeavesLaravelsMessages`. Wherever the core degrades, the binding adds no catch of its own: `testDoesNotSwallowAFailureTheSdkLetThrough` in both `LangsysTranslatorTest` and `TranslateResponseTest`, M7b, M9. |
| WIRE-5 | delegated | - | Core `WIRE-5`: implemented. This package maps it onto `langsys.api_url` and `LANGSYS_API_URL`. `ServiceProviderTest::testTheConfiguredApiUrlIsWhereTheSdkConnects` observes the configured address in a real connection attempt, and `::testAnApiUrlChangedAfterTheClientIsBuiltIsNotUsed` shows a change after the client is built reaches nothing. M20. |
| CONF-1 | implemented | contract | Tests assert on what the server accepted, read back from the shared contract fixture — `tests/Contract/`, the double vendored from langsys-js-typescript at tree `542f57f5` — never on what the SDK sent: server renders, the page walk's registration, capability drift with its positive control, the request locale against the served set, negotiated messages, and sync. Rows name each Laravel route a rule was proven on: PHP-FPM, Octane, queue job finished and thrown, `__()`, `@lang`, `translatePage()`, a failed validation, and `langsys:sync`. |
| CONF-2 | implemented | n/a (pure) | Every row carries exactly one status and one tier, and the pairing is checked mechanically. The script under *Computed summary* exits non-zero on any missing, duplicated or unknown rule id, and on an unrecognised status or tier. It rejects an `implemented` row whose tier is not `live`, `contract` or `n/a (pure)`, and a `provisional` row whose tier is not `mock` or that does not name what it waits on. A `delegated` row must carry tier `-`, and the summary resolves it against the core's current grade in `../langsys-php-sdk/CONFORMANCE.md`, as the fleet checker does. |
| CONF-3 | implemented | n/a (pure) | Every guard this package owns was mutated in place in a git worktree bound to the core by provenance, and every mutant reddened a named test; see *Mutation record*. Each test builds a fresh application and binds its own `Client`, and the Octane leg's stand-in event is recorded under GATE-3. |

## Pending the operator's ruling

These changes are in the code at this tip, but the operator holds the sign-off. Each rests on a binding rule, and what each row becomes if the change is reverted is stated here, so a reversal regrades the rows instead of silently invalidating them. The restore paths are in `ROADMAP.md`.

- **Removal of `auto_flush`.** Rests on it: BIND-4. **If restored:** BIND-4 is no longer green unless the operator records a waiver, and `BindingBoundaryTest::testEveryConfigKeyIsACoreOptionOrLaravelWiring` gains the key. Under PHP-FPM the setting would still not stop a flush, because the core's shutdown handler sends the queue regardless. It must never gate the long-lived reset, or GATE-3 and SRV-2 regress.
- **Removal of the `TranslateResponse` page cache.** Rests on it: BIND-5, CACHE-1, GATE-4. **If restored as a waiver:** BIND-5 becomes `waived`, citing the operator's recorded agreement. The restored key must carry the project id or CACHE-1 fails. GATE-7 gains a recorded limit — a cache hit feeds no lane — and `TranslateResponseTest::testEveryRequestReachesTheSdk` narrows to the cache-disabled case.
- **Lowercase `initialTranslationsLocale`.** Rests on it: WIRE-3 and SRV-4. **If `es-ES` is kept:** both stay green, because the JS core canonicalizes either spelling on receipt. WIRE-3's "every locale this package hands the core" narrows to "every PHP-core boundary", and M15's tests revert to canonical expectations.

## Gaps, ranked by cost

Ranked by what each gap costs someone running the Laravel stack, not by rule order.

1. **VAR-5 — no Blade value markers yet.** A page `TranslateResponse` walks registers the text it renders, values included, until the emitter ships; VAR-4 holds it for the readers' release. `__()` is unaffected: it registers only through sync, with placeholders.
2. **FRM-4 — a Mailable sent synchronously from an Inertia page answers with the source.** Laravel has no hook before a Mailable renders; notifications and queued mail are translated.
3. **MSG-5 — Blade-only pages show validation errors in the source language.** Entries carry source templates for a client to render; a page with no JS SDK has no client.

## Release gate

- **This tip needs the 838 core, and the core is untagged.** The code calls `resetRequestState()`, `resolveRequestLocale()`, `resolve()`, `translateRich()`, `markResolved()`, `useMissFallback()`, `planSync()`, the migration mode, the server-message API and the snapshot seam, none of which v1.3.1 has, and `^1.3` still resolves to v1.3.1 from Packagist. So CI, which installs from Packagist, fails on this branch by design. At publication, the constraint moves to the core's 838 tag.
- **The local path repository comes out at publication.** Locally, `composer.json` carries an uncommitted path repository to `../langsys-php-sdk`, recorded in `ROADMAP.md`.

## Mutation record

Each mutant was applied in place in a `git worktree` of this tip, with `vendor/langsys/langsys-php` linked to core `9ff7b35` and that binding asserted by reflection before the run, then run against the whole suite and reverted; the `X` mutants ran against the contract tests. **All 143 reddened a named test; none survived.** Run 2026-09-30. The first column's letter names the area: `M` the lifecycle, translator, page, cache and Inertia guards; `T`, `F` and `P` the catalog translator, its file list and its wiring; `L` the request locale; `C` and `V` entry codes; `S`, `K` and `R` the listing source, the sync and listing commands, and runtime agreement, rule-object templates included; `A`, `E` and `N` label advice, piece names and the snapshot; `B` rule-object message spans; `G` the negotiated JSON message (FRM-5); `W` response kinds (FRM-4); `X` the contract tests; `Z` the fallback chain, escaping, the resolved marker, value sets and sync (FRM-2 to FRM-8, GATE-10); `D` laravel-data requests; `Q` an app with no key (FRM-1, FRM-3); `Y` a listing that carries on past what it cannot read. The locale this package hands the catalog is normalized, which a contract test cannot observe, since the double answers either spelling; M6 covers it.

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
| M8 | flush on the request path | `testABladePageServesTheCatalogAndRegistersNothing`, `testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse`, `testAKeyThatMayNotWriteRegistersNothingEvenWhenTheWorldChanges`, `testDiscoveredPhrasesAreFlushedAfterTheResponse`, and 2 more |
| M9 | middleware re-adds a catch | `testDoesNotSwallowAFailureTheSdkLetThrough` |
| M10 | middleware memoizes pages | `testSetsTheClientLocaleFromTheAppLocale`, `testPassesTheConfiguredCategory`, `testAKeyThatMayNotWriteRegistersNothingEvenWhenTheWorldChanges`, `testExceptPathsAreExcluded`, and 5 more |
| M11 | cache index unscoped | `testClearingOneProjectLeavesAnotherProjectsKeys`, `testTheProviderScopesTheCacheIndexToTheProject` |
| M12 | adapter TTL back to 3600 | `testTheConfiguredTtlAppliesWhenTheSdkPassesNone` |
| M13 | Inertia hand-off loses its catch | `testAnUnreachableApiHandsNoSeedInsteadOfThrowing` |
| M14 | Inertia re-reads the shared cache | `testHandsTheClientTheCatalogThisRequestRenderedWith` |
| M15 | Inertia canonical-cased | `testBuildsTheJsSdkSeedingShape`, `testExplicitLocaleOverridesTheAppLocale`, `testEveryHostSpellingHandsTheSdkForm` |
| M16a | canWrite planted | `testTranslateReachesTheTranslator`, `testTranslatePassesCategoryAndParamsThroughToTheSdk`, `testReturnsExactlyWhatTheSdkReturned`, `testEveryHostSpellingOfALocaleReadsTheSameCatalog`, and 4 more |
| M16b | md5 planted | `testTheBindingReimplementsNoIdentityOrRenderingBehaviour` |
| M16c | usleep planted | `testTheBindingOwnsNoNetworkBehaviour` |
| M17 | auto_flush re-added | `testEveryConfigKeyIsACoreOptionOrLaravelWiring` |
| M18 | new public method | `testThePublicSurfaceIsTheOneArguedForHere` |
| M19 | DetectLocale never sets app locale | `testABladePageServesTheCatalogAndRegistersNothing`, `testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse`, `testTheRequestLocaleIsValidatedAgainstTheProjectsLocales`, `testABareLanguageCandidateMapsToTheProjectsDefault`, and 12 more |
| M20 | provider ignores api_url | `testTheMessageIsInTheLanguageTheServerServes`, `testAnUnservedLanguageGetsTheSourceInTheBaseLocale`, `testABladePageServesTheCatalogAndRegistersNothing`, `testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse`, and 11 more |
| M21 | translator throws where SDK degrades | `testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing` |
| T1 | validation keys go to Langsys | `testTheFailedFormPageIsTheCommittedFixture`, `testAFailingClientLeavesLaravelsMessages`, `testTheEntriesReachTheNextPageAsAProp`, `testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation`, and 19 more |
| T2 | miss not converted | `testAnUnreachableApiIsPlainLaravelToo`, `testASentenceNoFileHoldsIsConverted`, `testAPipePluralNoFileHoldsIsConvertedToo`, `testWithNeitherTheSourceIsReturnedFilled`, and 4 more |
| T3 | hit choice without count | `testAnUnreachableApiIsPlainLaravelToo`, `testAPipePluralRendersThroughIcuOverCount`, `testAJsonPluralRendersThroughIcuOverCount`, `testATranslatedPluralSelectsForTheRequestLocale`, and 1 more |
| T8 | choice read as __ | `testAnUnreachableApiIsPlainLaravelToo`, `testAPipePluralRendersThroughIcuOverCount`, `testAJsonPluralRendersThroughIcuOverCount`, `testAPipePluralNoFileHoldsIsConvertedToo`, and 2 more |
| T9 | no warning | `testACapitalisingPlaceholderIsLookedUpAsWrittenAndWarned` |
| T10 | miss converted by file table | `testASentenceKeepsWhatLaravelWouldPrintAsWritten` |
| T4 | countable not counted | `testAPipePluralRendersThroughIcuOverCount` |
| T5 | has() inherited | `testHasAnswersWhetherAFileHoldsTheKey` |
| T6 | explicit locale ignored | `testAnInertiaPageGetsTheSource`, `testAnInertiaVisitGetsTheSource`, `testTheRouteGroupOverrideWins`, `testANotificationSentFromAClientPageIsTranslated`, and 10 more |
| T7 | choice validation to Langsys | `testValidationKeysStayWithLaravel` |
| F1 | validation.php included | `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale`, `testValidationLinesAreNotMigrationSourceFiles` |
| F2 | app groups dropped from own tier | `testEveryLiteralCallAndBaseLanguageLineIsRegistered`, `testARuntimeKeyInsideALiteralGroupIsCoveredByTheGroup`, `testTheCountsNameWhatTheLangFilesAlreadyTranslate`, `testMigrateModeListsTheLangFilesProblems`, and 14 more |
| F3 | app path not excluded from fallback | `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F4 | tiers swapped | `testMigrateModeListsTheLangFilesProblems`, `testTheFrameworksOwnLinesAnswerWhatTheAppDoesNotDefine`, `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F5 | namespace tiers swapped | `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F6 | JSON dropped | `testTheCountsNameWhatTheLangFilesAlreadyTranslate`, `testAnUnreachableApiIsPlainLaravelToo`, `testAJsonKeyResolvesToItsLine`, `testAJsonPluralRendersThroughIcuOverCount`, and 1 more |
| F7 | JSON after groups | `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| F8 | JSON format undeclared | `testAJsonPluralRendersThroughIcuOverCount`, `testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale` |
| P1 | migration files while switched off | `testTheClientIsBuiltWithNoMigrationFiles` |
| P2 | translator while switched off | `testLaravelsOwnTranslatorAnswers` |
| P3 | eager Client | `testEveryCallReturnsPlainLaravelsOutput`, `testAnUnreachableApiIsPlainLaravelToo`, `testAPageAndAFailedFormStillServe`, `testSyncSaysWhatItNeeds`, and 18 more |
| P4 | fallback locale dropped | `testTheServerNeverEmitsTranslatedText`, `testAnInertiaPageGetsTheSource`, `testAnInertiaVisitGetsTheSource`, `testTheRouteGroupOverrideWins`, and 1 more |
| L1 | resolved flag ignored | `testALocaleTheAppResolvedIsServedAsItIs`, `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase` |
| L2 | listener dropped | `testALocaleTheAppResolvedIsServedAsItIs`, `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase`, and 1 more |
| L3 | app locale not mapped | `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase` |
| L4 | app branch ignores client | `testALocaleTheAppResolvedIsServedAsItIs`, `testTheAppSettingItsDefaultLocaleCountsAsResolved`, `testALocaleTheAppResolvedIsMappedToTheProjectsForm`, `testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase` |
| L15 | defaults dropped from match | `testABareLanguageCandidateMapsToTheProjectsDefault` |
| V1 | text entry gets a code | `testATextOnlyFailureCarriesNoCode` |
| L5 | order ignored | `testTheSourcesAreAskedInTheConfiguredOrder` |
| L6 | supported ignored | `testTheSupportedListNarrowsTheProjectsLocales` |
| L7 | project not validated | `testTheRequestLocaleIsValidatedAgainstTheProjectsLocales`, `testABareLanguageCandidateMapsToTheProjectsDefault`, `testTheSupportedListNarrowsTheProjectsLocales`, `testAnUnsupportedCookieFallsThroughAndIsNotReset` |
| L8 | no vary | `testTheRequestLocaleIsValidatedAgainstTheProjectsLocales`, `testTheCookieBeatsTheHeaderAndTheResponseVariesOnCookie`, `testASessionValueIsAStoredLocale`, `testTheHeaderIsNegotiatedAndTheResponseVariesOnIt`, and 2 more |
| L9 | header-read vary dropped | `testWithNothingUsableTheBaseLocaleIsServed` |
| L10 | cookie varies as query | `testTheCookieBeatsTheHeaderAndTheResponseVariesOnCookie`, `testASessionValueIsAStoredLocale` |
| L11 | persist any source | `testTheSupportedListNarrowsTheProjectsLocales`, `testAnUnsupportedCookieFallsThroughAndIsNotReset` |
| L12 | unreachable project still resolves | `testAnUnreachableProjectLeavesTheAppLocaleAlone` |
| L13 | session persist dropped | `testAQueryChoicePersistsToTheSessionWhenConfigured` |
| L14 | SDK sends Vary | `testTheClientNeverSendsVaryItself` |
| C1 | studly code | `testTheEntriesReachTheNextPageAsAProp`, `testTheFailedFormPageIsTheCommittedFixture`, `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testALaravelDataRequestIsListedLikeAFormRequest`, and 18 more |
| C2 | class code snaked | `testARuleObjectOrClosureCarriesTheClassLaravelRecords`, `testARuleObjectCarriesItsOwnMessageBesideAnotherFailure` |
| S1 | wildcard label check off | `testWhatCannotBeListedIsNamedWithItsFix` |
| S2 | a rule object with no message is silent | `testARuleThatThrowsIsReportedAndTheListingContinues`, `testWhatCannotBeListedIsNamedWithItsFix` |
| S3 | silent rules not skipped | `testWhatCannotBeListedIsNamedWithItsFix` |
| S4 | value not seeded | `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testWhatARequestSendsIsWhatWasListed` |
| S5 | messages dropped | `testACustomMessageIsListedAsTheAppWroteIt`, `testWhatARequestSendsIsWhatWasListed`, `testWhatCannotBeListedIsNamedWithItsFix` |
| S6 | labels dropped | `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testALaravelDataRequestIsListedLikeAFormRequest`, `testARuleThatThrowsIsReportedAndTheListingContinues`, `testWhatARequestSendsIsWhatWasListed`, and 1 more |
| S7 | unbuildable swallowed | `testWhatCannotBeListedIsNamedWithItsFix` |
| S8 | routes ignored | `testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn`, `testALaravelDataRequestIsListedLikeAFormRequest`, `testARuleObjectWithAMessageIsListedForEachField`, `testARuleObjectWithATemplateIsListedWithItsMarkers`, and 11 more |
| S9 | no-line rule silent | `testWhatCannotBeListedIsNamedWithItsFix` |
| K1 | strict ignored | `testTheCommandReportsByDefaultAndFailsUnderStrict` |
| K2 | register skipped | `testRegisterSendsWhatTheCatalogLacks` |
| K3 | legacy source dropped | `testMigrateModeListsTheLangFilesProblems` |
| K4 | verbose silent | `testVerboseListsEachTemplate` |
| K5 | problems silent | `testMigrateModeListsTheLangFilesProblems` |
| K6 | listing command unregistered | `testMigrateModeListsTheLangFilesProblems`, `testAFieldWithNoDeclaredLabelNeverFailsTheBuild`, `testTheCommandReportsByDefaultAndFailsUnderStrict`, `testVerboseListsEachTemplate`, and 1 more |
| R1 | runtime diverges | `testTheMessageIsInTheLanguageTheServerServes`, `testAnUnservedLanguageGetsTheSourceInTheBaseLocale`, `testTheEntriesReachTheNextPageAsAProp`, `testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation`, and 18 more |
| A1 | label advice off | `testTheFailedFormPageIsTheCommittedFixture`, `testAFieldWithNoDeclaredLabelIsNamed`, `testAFieldWithNoDeclaredLabelNeverFailsTheBuild` |
| E1 | pieces ignored | `testThePieceNamesAreConfigurable` |
| N1 | snapshot not passed | `testAConfiguredSnapshotAnswersWithNoNetwork`, `testASnapshotTheCoreRefusesIsReportedAndSkipped` |
| N2 | refusal not reported | `testASnapshotTheCoreRefusesIsReportedAndSkipped` |
| N3 | refusal throws | `testASnapshotTheCoreRefusesIsReportedAndSkipped` |
| B1 | rule object reads the first message | `testARuleObjectCarriesItsOwnMessageBesideAnotherFailure`, `testEachFailureOfARuleObjectIsItsOwnEntry` |
| B2 | every rule owns one message | `testEachFailureOfARuleObjectIsItsOwnEntry` |
| G1 | no Content-Language | `testTheMessageIsInTheLanguageTheServerServes`, `testAnUnservedLanguageGetsTheSourceInTheBaseLocale`, `testTheMessageIsInTheNegotiatedLanguage`, `testALocaleTheAppResolvedIsTheMessagesLanguage` |
| G2 | no Vary | `testTheMessageIsInTheLanguageTheServerServes`, `testTheMessageIsInTheNegotiatedLanguage`, `testAnUnservedLanguageGetsTheSourceInTheBaseLocale` |
| G3 | message left as source | `testTheMessageIsInTheLanguageTheServerServes`, `testTheMessageIsInTheNegotiatedLanguage`, `testALocaleTheAppResolvedIsTheMessagesLanguage` |
| G4 | app-resolved locale ignored | `testALocaleTheAppResolvedIsTheMessagesLanguage` |
| G5 | redirect entries translated | `testThePropCarriesSourceTextEvenWhenTheCatalogHasATranslation` |
| W1 | client kind ignored | `testAnInertiaPageGetsTheSource`, `testAnInertiaVisitGetsTheSource`, `testTheRouteGroupOverrideWins`, `testANotificationSentFromAClientPageIsTranslated` |
| W2 | X-Inertia ignored | `testAnInertiaVisitGetsTheSource` |
| W3 | Inertia route ignored | `testAnInertiaPageIsNotMarked`, `testAnInertiaPageGetsTheSource`, `testANotificationSentFromAClientPageIsTranslated` |
| W4 | override ignored | `testTheRouteGroupOverrideWins` |
| W5 | notifications not tracked | `testANotificationSentFromAClientPageIsTranslated` |
| W6 | sending ignored | `testANotificationSentFromAClientPageIsTranslated` |
| X1 | no flush after the response | `testThePageWalkServesTheCatalogAndRegistersTheMissAfterTheResponse`, `testAKeyThatMayNotWriteRegistersNothingEvenWhenTheWorldChanges` |
| X2 | candidates not validated | `testTheRequestLocaleIsValidatedAgainstTheProjectsLocales` |
| X3 | header not negotiated | `testTheRequestLocaleIsValidatedAgainstTheProjectsLocales` |
| Z1 | runtime registration left on | `testABladePageServesTheCatalogAndRegistersNothing`, `testAFailureRegistersNothingAtRuntime`, `testNothingRegistersAtRuntime`, `testAnUndeclaredValueStaysAPlaceholder` |
| Z2 | no miss fallback | `testALangFileTranslationAnswersWhatTheCatalogLacks`, `testTheAppsOwnLangFileMarkupPrintsAsLaravelPrintsIt` |
| Z3 | @lang prints unescaped | `testCatalogMarkupIsEscapedThroughLangAndT`, `testARichSourceLineIsRebuiltFromTheSourcesElements`, `testATranslatorAddedTagIsEscapedAndAnUnknownTokenDropped` |
| Z4 | @lang left to Laravel | `testCatalogMarkupIsEscapedThroughLangAndT`, `testARichSourceLineIsRebuiltFromTheSourcesElements` |
| Z5 | fallback line unconverted | `testAPipePluralRendersThroughIcuOverCount`, `testAJsonPluralRendersThroughIcuOverCount`, `testALangFileTranslationAnswersWhatTheCatalogLacks` |
| Z6 | page never marked | `testABladePageServesTheCatalogAndRegistersNothing`, `testOnlyGroupsTheApplicationDefinesAreAppendedTo`, `testAPageRenderedInANonBaseLocaleIsMarkedResolved`, `testTheServedBytesCarryTheRequestLocalesTranslations` |
| Z7 | client page marked | `testAnInertiaPageIsNotMarked` |
| Z8 | untouched app marked | `testAPageAndAFailedFormStillServe`, `testAPageThatTranslatedNothingIsLeftAlone` |
| Z9 | any backed enum declared | `testDeclarationsAreFoundWithoutConfigurationAndAddedFromConfig` |
| Z10 | configured sets ignored | `testDeclarationsAreFoundWithoutConfigurationAndAddedFromConfig`, `testTheCacheIsWrittenAndRead` |
| Z11 | sets not given to the client | `testADeclaredValueLooksUpTheSentenceWithTheWordWrittenIn`, `testWithoutALabelTheValueIsTheWord`, `testAValueAddedSinceTheLastSyncRegistersItsSentence` |
| Z12 | Blade not scanned | `testEveryLiteralCallAndBaseLanguageLineIsRegistered`, `testALineHoldingALabelPlaceholderRegistersOnlyThroughTheValidationListing`, `testANonLiteralCallIsReportedWithItsFileAndLineAndFailsStrict`, `testTheCountsNameWhatTheLangFilesAlreadyTranslate` |
| Z13 | strict ignored | `testANonLiteralCallIsReportedWithItsFileAndLineAndFailsStrict` |
| Z14 | dry run registers | `testTheCountsNameWhatTheLangFilesAlreadyTranslate` |
| Z15 | no target translations | `testTheCountsNameWhatTheLangFilesAlreadyTranslate` |
| Z16 | rendering compiler scans | `testEveryLiteralCallAndBaseLanguageLineIsRegistered`, `testALineHoldingALabelPlaceholderRegistersOnlyThroughTheValidationListing`, `testTheCountsNameWhatTheLangFilesAlreadyTranslate` |
| Z17 | blade lines not remapped | `testANonLiteralCallIsReportedWithItsFileAndLineAndFailsStrict` |
| D1 | laravel-data not a request | `testALaravelDataRequestIsListedLikeAFormRequest`, `testARuleObjectWithAMessageIsListedForEachField`, `testARuleObjectWithoutATemplateIsNamedWithTheInterface`, `testLaravelsOwnRuleObjectsAreListedFromLaravel` |
| D2 | nested objects not sampled | `testALaravelDataRequestIsListedLikeAFormRequest` |
| D3 | rule object message not listed | `testARuleObjectWithAMessageIsListedForEachField`, `testARuleObjectWithoutATemplateIsNamedWithTheInterface` |
| D4 | empty message not reported | `testWhatCannotBeListedIsNamedWithItsFix` |
| R2 | listing ignores the template | `testARuleObjectWithATemplateIsListedWithItsMarkers`, `testWhatARequestSendsIsWhatWasListed` |
| R3 | runtime ignores the template | `testWhatARequestSendsIsWhatWasListed`, `testARuleObjectWithATemplateIsSentFromIt` |
| R4 | filled message listed with no problem | `testARuleObjectWithoutATemplateIsNamedWithTheInterface` |
| R5 | runtime label not Laravel's | `testWhatARequestSendsIsWhatWasListed`, `testARuleObjectWithATemplateIsSentFromIt` |
| S10 | via-validation lines not reported | `testALineHoldingALabelPlaceholderRegistersOnlyThroughTheValidationListing` |
| Q1 | translator builds a client with no key | `testEveryCallReturnsPlainLaravelsOutput`, `testTheCauseIsReportedOncePerProcessAtDebug`, `testAPageAndAFailedFormStillServe` |
| Q2 | locale middleware builds a client with no key | `testAPageAndAFailedFormStillServe` |
| Q3 | JSON negotiation builds a client with no key | `testAPageAndAFailedFormStillServe` |
| Q4 | validator builds a client with no key | `testAPageAndAFailedFormStillServe` |
| Q5 | notice every call | `testTheCauseIsReportedOncePerProcessAtDebug` |
| Q6 | page walk builds a client with no key | `testAPageAndAFailedFormStillServe` |
| Q7 | flush builds a client nobody used | `testAPageAndAFailedFormStillServe` |
| Q8 | credentials not required | `testEveryCallReturnsPlainLaravelsOutput`, `testSyncSaysWhatItNeeds`, `testTheCauseIsReportedOncePerProcessAtDebug`, `testAPageAndAFailedFormStillServe` |
| S11 | covered calls not reported | `testARuntimeKeyInsideALiteralGroupIsCoveredByTheGroup` |
| S12 | validation group not named as covered | `testARuntimeKeyInsideALiteralGroupIsCoveredByTheGroup` |
| A2 | label note fails the build | `testAFieldWithNoDeclaredLabelIsNamed`, `testAFieldWithNoDeclaredLabelNeverFailsTheBuild` |
| A3 | advice not printed | `testAFieldWithNoDeclaredLabelNeverFailsTheBuild` |
| Y1 | rule given no validator | `testLaravelsOwnRuleObjectsAreListedFromLaravelsLine` |
| Y2 | Laravel rule objects asked for a template | `testLaravelsOwnRuleObjectsAreListedFromLaravelsLine` |
| Y3 | one field aborts the listing | `testALaravelDataRequestIsListedLikeAFormRequest`, `testARuleObjectWithAMessageIsListedForEachField`, `testARuleObjectWithoutATemplateIsNamedWithTheInterface`, `testLaravelsOwnRuleObjectsAreListedFromLaravelsLine`, and 3 more |

M20 substitutes a second closed port rather than removing the setting: removing it would point a mutant at the production API.

## Raised against the spec

Measured here and raised through the Reviewer; not findings against this package.

- **SRV-2:** its test calls sequential renders proof of nothing, but in a per-request runtime the interleave cannot occur, and sequential reuse across a boundary is the failure that exists. It is worth asking whether the test wording should admit that.

## Computed summary

Produced by the script below, run from the repository root with `langsys2` and `langsys-php-sdk` checked out alongside. It exits non-zero on a malformed file. A red grade is a fact about the SDK, not a defect in this file, so it is reported rather than failed on.

```
spec blob, re-derived             295974b4c2c9e63af82d48513514f5b78e6c088a
rule ids in the spec              129
rowed exactly once                129
missing / duplicated / unknown    0 / 0 / 0
malformed rows                    0
as graded in this file
  implemented                     39
  delegated                       55
  n/a                             33
  provisional                     0
  waived                          0
  partial                         1
  not implemented                 1
  held (strip ruling)             0
delegated rows resolved against langsys-php-sdk 9ff7b35
  implemented                     54
  provisional                     1
counting red                      VAR-5, FRM-4
GREEN, provisional counted apart  no
```

```python
import collections, re, subprocess, sys

T = 'bb41605e362c84bb853345f54f7230f934b164b6'
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
