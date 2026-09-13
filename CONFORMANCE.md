# Conformance — langsys/langsys-php-laravel

| **Spec revision read** | langsys2 5cff03a1…, docs/sdk-spec.mdx blob 5c5c0723f88fb8e6b13f58876c7adca8b6b35691 |
|---|---|
| **Profiles** | server, binding, all — derived: binding over langsys-php-sdk |
| **Spec version** | 8.0.1, committed at `5cff03a17751e7dae9dcf1af52a9454d027c9006` and deliberately unpublished |
| **Core bound against** | langsys-php-sdk `5400248` (`v1.3.1-43`, `feature/838_write_key_gating_reland`, untagged) — see *Release gate* |
| **Derived** | 2026-09-13T00:16:52Z by `git rev-parse` on the commit above; the text graded is byte-identical to the text read |

**The spec never names Laravel.** No profile line mentions a PHP framework binding, so the profile above is derived: a binding inherits its core's profile (`server`, and `all`) and adds `binding`. That gap is flagged to the operator.

Every rule id in the spec is rowed exactly once, including the ones that do not bind this package. A row graded `implemented` names a test that fails when the behaviour is removed; the mutation that proved it is in *Mutation record*. `delegated` names the core row it rests on and the absence probe that shows this package does not do the work itself, with a firing control that shows the probe can fail. `provisional` rows rest on evidence that stands in for the API's answer, and wait on the shared contract fixture. Counts live only in *Computed summary*, which is produced by a script, not typed.

**A `delegated` row is only as good as the core row it cites, and it is graded that way.** Its tier is `-`, because the behaviour's tier lives on the core row, and the fleet checker resolves each delegated row against the core's current grade. Three cite core rows graded `not implemented` — REG-8, REG-11 and OBS-1 — so they count red in this file until the core's rows turn green. A binding may not do that work (BIND-3, BIND-2), so nothing in this package can turn them green. They lead the ranked gaps.

**Three changes graded here are pending the operator's ruling, not accepted:** the removal of `auto_flush`, the removal of the `TranslateResponse` page cache, and the lowercase Inertia locale. The rows below grade the code at this tip, and each row that rests on one of those changes says so. *Pending the operator's ruling* lists what each row becomes if a change is reverted.

Test paths are under `tests/`. In an evidence cell, `::name` continues the last file named in that cell. `M` numbers refer to *Mutation record*.

## What surfaced while writing this

Every item below was found by executing code against the rule — a probe, a real-core test or a mutant — and none by reading the code or the review list.

- **`InertiaSsrProps::share()` turned an API outage into a 500 on every Inertia page.** `getTranslations()` throws by design, and `share()` runs on every Inertia request (WIRE-4). It was not on the review list.
- **Queue workers had no request boundary at all.** A queue worker keeps the `Client` singleton exactly as Octane does, but only Octane was wired. Write decisions and catalogs leaked from job to job, and discovered phrases waited for the worker to exit (GATE-3, SRV-2, REG-3).
- **The Octane listener could not have carried a reset.** It returned early unless `auto_flush` was on and something was queued, so a reset added at its end would have been skipped on most requests. It also resolved the `Client` on every request, and without credentials that constructor throws.
- **`LaravelCacheAdapter`'s key index was shared between projects.** The review's CACHE-1 finding named the page cache. The adapter's own `__key_index` had the same defect, and worse: `clear()` for one project evicted another's catalog.
- **Four catches were dead code.** The ones in `LangsysTranslator`, `TranslateResponse`, `terminate()` and the Octane listener could never fire against the 838 core — measured with a throwing cache and an unreachable API. So "the wrapper swallows registration failures silently" was never true at runtime. The defect was reimplementation.
- **Reviewer finding 6 inverts under measurement.** The question was whether wrapper-side casing fought the core's lowercasing. `Client::translate()` does not normalize the locale it is handed at all, so the wrapper's normalization is what keeps `es-ES` and `es-es` on one catalog entry.
- **`LocaleFormatter`'s premise was stale.** It canonicalized the Inertia hand-off because "the JS SDKs expect canonical". The JS core on 838 lowercases whatever it is handed.
- **`langsys.cache.ttl` never took effect**, because the SDK passes no TTL and the adapter's default matched the interface's. **`auto_flush=false` never stopped a flush under PHP-FPM**, because the core's shutdown handler sends the queue regardless.
- **The page cache fed neither lane on a hit.** `translatePage()` never ran, so a page first rendered under a read-only key could be served from cache indefinitely with nothing ever registered (GATE-7).

## Status

| Rule | Status | Tier | Evidence |
|---|---|---|---|
| GATE-1 | delegated | - | Core `GATE-1`: provisional. The binding never reads `write_enabled` or `key_type` and never calls `canWrite()`: `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`, firing control `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`, M16a. |
| GATE-2 | n/a (architecture: synchronous core — the write decision resolves in-line at the send site, so no unknown window exists to hold a phrase through; live if the core became asynchronous) | - | The spec records synchronous SDKs `n/a` here. The residual obligation is REG-10, delegated below. |
| GATE-3 | implemented | n/a (pure) | **Reviewer finding 1, closed.** The binding's half is the explicit reset this rule requires of long-lived runtimes. Octane workers and queue workers keep the `Client` singleton across units of work, so the provider flushes and then calls `resetRequestState()` at every boundary: queue job processed, queue job threw, and Octane `RequestTerminated`. `RequestScopeTest::testTheNextUnitOfWorkStartsWithoutAWriteDecision` sets a decision, crosses each boundary and asserts the next unit starts without one. `::testEveryBoundaryFlushesAndThenResets` pins the flush before the reset, and `::testABoundaryNeverBuildsAClientNobodyUsed` covers an unused client. M1, M2, M3, M4, M5. The Octane leg dispatches a stand-in `Laravel\Octane\Events\RequestTerminated`: Octane is not installed, and the provider listens by class name. Keeping the decision out of any shared cache is the core's half, core `GATE-3`: provisional. PHP-FPM needs no reset, because the process ends the scope. No process-level carve-out is taken. |
| GATE-4 | delegated | - | Core `GATE-4`: provisional. The binding writes no cache entry of its own — the page cache is removed (pending the operator's ruling), `TranslateResponseTest::testEveryRequestReachesTheSdk`, M10 — and `LaravelCacheAdapter` stores exactly what the core hands it, `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. It never names the flag it would have to strip or add: capability scan, M16a. |
| GATE-5 | delegated | - | Core `GATE-5`: provisional. The binding keeps no registration bookkeeping and never reads a flush result; `flushPendingRegistrations()` is its only call on the write lane. `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `queuePhraseForRegistration`, `createPhrases`, `registerPhrases` and `clearPendingRegistrations`; firing control `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`; M16c. |
| GATE-6 | delegated | - | Core `GATE-6`: provisional, with its report half `n/a` there because the core has no hint lane. Neither does the binding: the network scan `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `discovery/hint` and all transport. It makes no write decision either, `::testTheBindingNeverTouchesServerComputedCapability`. |
| GATE-7 | delegated | - | Core `GATE-7`: provisional. Every Laravel route that can meet unregistered content hands it to a core entry point that feeds the register lane: `t()` and `@t` go to `translate()`, `TranslateResponse` goes to `translatePage()`, and Livewire and queued jobs go through `t()`. Pass-through probes: `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` and `TranslateResponseTest::testServesExactlyWhatTheSdkReturned`. `ServedBytesTest::testTheServedBytesCarryTheRequestLocalesTranslations` observes the miss the real core queued. The page cache — removed, pending the operator's ruling — fed neither lane on a hit, M10. |
| GATE-8 | delegated | - | Core `GATE-8`: provisional. The binding consults neither the flag nor the key type: `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`, M16a. |
| CAT-1 | delegated | - | Core `CAT-1`: provisional. The binding never indexes the catalog. The one place it holds one keeps present-with-null present: `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. `InertiaSsrProps` hands `getTranslations()` through unmodified: `InertiaSsrPropsTest::testHandsTheClientTheCatalogThisRequestRenderedWith`. |
| CAT-2 | delegated | - | Core `CAT-2`: provisional. **Reviewer finding 5, closed.** `LangsysTranslator` fell back to the interpolated source phrase on a null or a thrown result, duplicating the core, and that fallback is removed. `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` hands back a result no fallback could produce, and M7a re-interpolating it reddens. `::testDoesNotSwallowAFailureTheSdkLetThrough` covers a failure, M7b. The identity scan `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `Interpolator` and `getInterpolator`. |
| CAT-3 | delegated | - | Core `CAT-3`: provisional. The binding never resolves a content block: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `translateContentBlock`, `HtmlParser` and `PageTranslator`. `TranslateResponse` hands over the response bytes untouched, `TranslateResponseTest::testTranslatesAnHtmlResponse`. |
| REG-1 | delegated | - | Core `REG-1`: provisional; the core drops the queue without a request when this request may not write. The binding makes no write decision, `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`. `terminate()` and the boundary listener call `flushPendingRegistrations()` without a guard of their own. |
| REG-2 | n/a (architecture: request-scoped flush — the misses of a request or a job leave together when it ends, so there is no stream of sends to debounce; live if this package flushed on a timer, for instance inside a daemon loop) | - | One flush per unit of work: `FlushPendingRegistrationsTest::testDiscoveredPhrasesAreFlushedAfterTheResponse` and `RequestScopeTest::testEveryBoundaryFlushesAndThenResets`. No timers: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `sleep` and `usleep`. Batching one flush into one request is REG-9, delegated. |
| REG-3 | implemented | n/a (pure) | **The automatic path is this package's, and it is proven on every way a Laravel execution context ends.** PHP-FPM: `terminate()` flushes after the response, `FlushPendingRegistrationsTest::testDiscoveredPhrasesAreFlushedAfterTheResponse`, and `::testTheFlushRunsOnlyOnceTheResponseExists` pins the order of events, M8. Octane request, and queue job finished or thrown: `RequestScopeTest::testEveryBoundaryFlushesAndThenResets`, M1, M4, M5. A flush that cannot reach the API fails nothing and keeps the queue, `::testAFlushThatCannotReachTheApiFailsNothing`. The public manual flush is the core's, reachable as `Langsys::client()->flushPendingRegistrations()`, `FacadeTest::testClientReturnsTheContainersSdkClient`, and the README documents it beside the automatic path. **Not claimed: that a failed flush is logged.** The core logs it to a `NullLogger` unless its own logging is on, and core `REG-3` is not implemented — raised below. |
| REG-4 | n/a (profile: browser) | - | A server binding has no page teardown. |
| REG-5 | n/a (profile: browser) | - | A server binding has no page teardown. |
| REG-6 | n/a (architecture: synchronous flush — the core's send runs to completion before anything else in the request can queue, so nothing joins the live queue mid-send; live under a coroutine runtime such as Swoole with coroutine hooks, where a t call could run during an in-flight send) | - | The binding never touches the queue: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| REG-7 | n/a (architecture: synchronous flush — one send completes before the next statement runs, so two cannot be in flight; live under the same coroutine runtime as REG-6) | - | As REG-6. |
| REG-8 | delegated | - | Core `REG-8`: **not implemented** — a failed send stays queued, with no retry and no backoff. The binding may not supply either, because BIND-3 forbids retries and timers: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`, M16c. **Counts red here while the core row is `not implemented`; the Laravel stack does not back off.** The retained queue survives `resetRequestState()`, so in a long-lived worker a failing endpoint receives a growing payload at every boundary. Gap 1. |
| REG-9 | delegated | - | Core `REG-9`: provisional. The binding does no batching and constructs no request: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `createPhrases`, `createContentBlocks` and transport. |
| REG-10 | delegated | - | Core `REG-10`: provisional; that row notes a split still open across the core's entry points. The binding adds no failure behaviour of its own. It never throws into a render from the write lane, `RequestScopeTest::testAFlushThatCannotReachTheApiFailsNothing`. Its flush call sites return nothing, success-shaped or otherwise. It swallows nothing the core lets through: `LangsysTranslatorTest::testDoesNotSwallowAFailureTheSdkLetThrough` and `TranslateResponseTest::testDoesNotSwallowAFailureTheSdkLetThrough`, M7b, M9. The *always log* half is the core's logger, which defaults to a `NullLogger` — raised below. |
| REG-11 | delegated | - | Core `REG-11`: **not implemented**. The binding inspects no phrase text: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour`. **Counts red here while the core row is `not implemented`.** Gap 4. |
| REG-12 | delegated | - | Core `REG-12`: provisional. The binding never inspects catalog structure: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour`, plus the pass-through probes under GATE-7. |
| HINT-1 | n/a (profile: browser) | - | A server binding has no report lane; HINT-2 governs. |
| HINT-2 | delegated | - | Server SDKs never report, and this package sends nothing at all: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `discovery/hint`, transport and request construction, M16c. The core covers HINT-2 with a `n/a (profile: server)` row spanning `HINT-1 … HINT-8`. HINT-2's profile *is* `server`, so that is a mis-grade — raised below. |
| HINT-3 | n/a (profile: browser) | - | No page URL and no report lane in a server binding. |
| HINT-4 | n/a (profile: browser) | - | As HINT-3. |
| HINT-5 | n/a (profile: browser) | - | As HINT-3. |
| HINT-6 | n/a (profile: browser) | - | As HINT-3. |
| HINT-7 | n/a (profile: browser) | - | As HINT-3. |
| HINT-8 | n/a (profile: browser) | - | As HINT-3. |
| HINT-9 | n/a (profile: browser) | - | As HINT-3. |
| HINT-10 | n/a (profile: browser) | - | As HINT-3. |
| HINT-11 | n/a (profile: browser) | - | As HINT-3. |
| HINT-12 | n/a (profile: browser) | - | As HINT-3; its server mirror is the langsys backend, not an SDK. |
| ICU-1 | delegated | - | Core `ICU-1`: provisional. Interpolation is the core's. The binding hands `$params` over raw, `LangsysTranslatorTest::testPassesParamsThroughToTheSdkSoTheRawPhraseIsRegistered`, and applies nothing to the result, `::testReturnsExactlyWhatTheSdkReturned`, M7a. `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `Interpolator` and `MessageFormatter`. |
| ICU-2 | delegated | - | Core `ICU-2`: provisional. Same evidence as ICU-1. |
| ICU-3 | delegated | - | Core `ICU-3`: provisional. Same evidence as ICU-1. |
| ICU-4 | delegated | - | Core `ICU-4`: provisional; the notice goes through the core's logger at debug level. Same evidence as ICU-1. In a default Laravel app that logger is a `NullLogger`. That satisfies "only when debug logging is enabled", and it also means nobody sees the notice until core logging is on — see *Deferred* in `ROADMAP.md`. |
| ICU-5 | delegated | - | Core `ICU-5`: provisional. Same evidence as ICU-1. |
| CID-1 | delegated | - | Core `CID-1`: provisional. The binding derives no id: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `md5`, `sha1`, `hash`, `crc32` and `json_encode`, M16b. |
| CID-2 | delegated | - | Core `CID-2`: provisional. The binding never spells a category sentinel: an uncategorized call passes `null` and the core names it. The identity scan forbids the literal `__uncategorized__`, M7c, and the lookup still resolves, `LangsysTranslatorTest::testAnUncategorizedPhraseResolvesThroughTheSdksOwnNamespace`. |
| CID-3 | delegated | - | Core `CID-3`: provisional. The binding reads content blocks only through `translatePage()`, which carries the core's tolerance, and resolves none itself: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour`. |
| CID-4 | delegated | - | Core `CID-4`: provisional. As CID-3. |
| TOK-1 | delegated | - | Core `TOK-1`: provisional. The binding tokenizes nothing: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `DOMDocument`, `DOMXPath`, `HtmlParser`, `MarkupTokenizer` and `preg_replace`. It exposes two routes to the tokenizer and both are the core's: `translate()` for a phrase, and `translatePage()` for markup, handed the exact response bytes, `TranslateResponseTest::testTranslatesAnHtmlResponse`. `TranslateResponseSafetyTest` runs the real page translator over a Laravel-shaped page: script and style bodies survive byte for byte, and nothing from them is queued. |
| TOK-2 | delegated | - | Core `TOK-2`: provisional. Same evidence as TOK-1. |
| TOK-3 | delegated | - | Core `TOK-3`: provisional. Same evidence as TOK-1. |
| TOK-4 | delegated | - | Core `TOK-4`: provisional. Same evidence as TOK-1. |
| TOK-5 | delegated | - | Core `TOK-5`: provisional. Both spellings belong to the core's interpolator and capture path; the binding passes phrases and params raw, as in ICU-1. |
| MARK-1 | delegated | - | Core `MARK-1`: provisional. The binding stamps nothing: `BindingBoundaryTest::testTheBindingReimplementsNoIdentityOrRenderingBehaviour` forbids `data-ls-` and `data-langsys-` in code. |
| MARK-2 | delegated | - | Core `MARK-2`: provisional. The binding reads no host marker: same scan as MARK-1. |
| SSR-1 | n/a (profile: browser) | - | Governs the browser SDK's strategy under server rendering. |
| SSR-2 | n/a (profile: browser) | - | As SSR-1. |
| SSR-3 | n/a (profile: browser) | - | As SSR-1. |
| SRV-1 | provisional | mock | Proven on the route an application renders — locale middleware, Blade, `@t` — with the real core. `ServedBytesTest::testTheServedBytesCarryTheRequestLocalesTranslations` finds `Prezzi` in the served bytes for `?locale=it-IT`. Its control is a phrase absent from the catalog, served in the base language and queued as a miss. M19. The catalog is seeded into the cache the core reads, standing in for the API's answer. Waits on: CONF-2 shared contract fixture, which would serve that catalog through the real request path. |
| SRV-2 | implemented | n/a (pure) | **Reviewer finding 1, closed.** PHP-FPM builds a container per request; Octane and queue workers do not, so the provider resets the core's per-request state at each boundary. `RequestScopeTest::testTheNextUnitOfWorkReadsTheCatalogAfresh` reads a catalog, moves the shared cache on, crosses each of the three boundaries and asserts the next unit reads the new catalog; M1, M4 and M5 each redden it. **The rule's interleave test is unreachable in this runtime model.** A PHP-FPM process and an Octane worker each serve one request at a time, so concurrent requests never share a `Client`. The reachable failure is sequential reuse across a boundary, which GATE-3 names for exactly these runtimes. It would become live with in-worker concurrency sharing the singleton, such as Swoole coroutine hooks. The catalog memo itself is the core's, core `SRV-2`: provisional. |
| SRV-3 | provisional | mock | **Reviewer finding 7, confirmed.** The order of events: the flush runs only once the response exists, `FlushPendingRegistrationsTest::testTheFlushRunsOnlyOnceTheResponseExists`, with `RequestHandled` before the flush; M8 moving the flush onto the request path reddens it. At long-lived boundaries the flush runs after the unit of work, `RequestScopeTest::testEveryBoundaryFlushesAndThenResets`. A read-only key pushing nothing is the core's decision, core `REG-1`, and the binding adds no capability branch, `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`. Graded on that API-dependent half. Waits on: CONF-2 shared contract fixture, for a read-only key with a write-key positive control on the same render. |
| SRV-4 | implemented | n/a (pure) | **Reviewer finding 4, graded on its evidence. This package holds the server's half, and names the two halves it does not.** Held: hand the client the catalog the server rendered with. `InertiaSsrProps::share()` reads the same `getTranslations()` memo that `t()` read. `InertiaSsrPropsTest::testHandsTheClientTheCatalogThisRequestRenderedWith` renders `@t` on the real core, moves the shared cache on, and still receives the rendered catalog; M14 re-reading the cache reddens it. The locale goes over in the form both SDKs identify it by, with the casing pending the operator's ruling: `::testEveryHostSpellingHandsTheSdkForm`, M15. An outage hands no seed instead of a 500, `::testAnUnreachableApiHandsNoSeedInsteadOfThrowing`, M13. Not held: the synchronous seed, which is langsys-js-typescript's (`init()` seeds from `initialTranslations` before its first fetch). Also not held: calling that seed before hydration, with the mismatch control, which belongs to the JS framework binding under Inertia (langsys-js-vue, langsys-js-react). A Blade page translated by `TranslateResponse` has no hand-off; it is terminal HTML, which the core rows `n/a` for the same structural reason. |
| SRV-5 | delegated | - | Core `SRV-5`: provisional. Once per subtree, on this package's own render route: `ServedBytesTest::testAMissRenderedManyTimesIsQueuedOnce` renders one miss eight times across three nested Blade loops on the real core, and counts one registration. The deduplication is the core's queue. The binding cannot add a registration of its own: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `queuePhraseForRegistration`, M16c. The fail-loudly half has no site here: Blade and Livewire render eagerly on the server, with no lazy child that resolves to a fallback. |
| BIND-1 | implemented | n/a (pure) | **Shape and timing only.** Timing: `terminate()` and the long-lived boundaries decide *when* the core flushes and resets. Shape: the Laravel cache adapter, the casing of Laravel's own locale store, the Inertia prop shape, and route scoping for `TranslateResponse`. Everything that is meaning is delegated and proven absent by the three scans in `BindingBoundaryTest`, each with firing control `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment` and coverage control `::testTheScanReadsEverySourceFile`. Pass-through probes cover both render routes: `LangsysTranslatorTest::testReturnsExactlyWhatTheSdkReturned` and `TranslateResponseTest::testServesExactlyWhatTheSdkReturned`. Removed as reimplementation: the fallbacks in `LangsysTranslator` and `TranslateResponse`, the dead catches in both flush paths, the page cache (pending the operator's ruling), and the `__uncategorized__` sentinel. M7a, M7b, M7c, M9, M10, M16b. |
| BIND-2 | implemented | n/a (pure) | `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability` finds no `write_enabled`, `key_type`, `canWrite`, `auto_discovery` or `ip_write` in code. Firing control: `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`. M16a plants `canWrite()` and reddens the scan. |
| BIND-3 | implemented | n/a (pure) | `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` finds no transport, auth or grant header, hint endpoint, batching, registration construction or timer. Firing control: `::testEveryScanFiresOnAPlantedViolationAndNotOnAComment`. M16c plants `usleep()` and reddens the scan. Flushing at a lifecycle boundary is timing under BIND-1, not scheduling. `DetectLocale`'s `Set-Cookie` is a header on the host application's own response, not on a request to Langsys. |
| BIND-4 | implemented | n/a (pure) | `BindingBoundaryTest::testEveryConfigKeyIsACoreOptionOrLaravelWiring` pins every key in `config/langsys.php` with its classification: either a core option mapped onto Laravel, or whether and where Laravel invokes the core. Firing control: `::testTheConfigCheckSeesAnAddedKey`. M17 re-adding `auto_flush` reddens the pin. **Reviewer finding 3, addressed in code; the removal is pending the operator's ruling.** `auto_flush` is removed. It was product configuration the core does not define, and under PHP-FPM it never stopped a flush, because the core's shutdown handler sends the queue regardless. `translate_response.cache.*` went with the page cache. |
| BIND-5 | implemented | n/a (pure) | `TranslateResponseTest::testEveryRequestReachesTheSdk`: two identical requests both reach `translatePage()`, and M10 memoizing the page reddens the test. **Reviewer finding 2, addressed by removing the cache rather than re-keying it — a removal pending the operator's ruling.** Adding a project id would have fixed CACHE-1 and still left a binding caching lookup results, which this rule forbids outright. `LaravelCacheAdapter` is the core's own cache mapped onto a Laravel store, not a binding cache, and key presence survives it: `LaravelCacheAdapterTest::testPresentWithNullSurvivesTheRoundTrip`. |
| BIND-6 | implemented | n/a (pure) | `BindingBoundaryTest::testThePublicSurfaceIsTheOneArguedForHere` pins every public method each class declares, plus `t()`; M18 adding one reddens it. `Langsys::client()` re-exports the core `Client` by reference, `FacadeTest::testClientReturnsTheContainersSdkClient`. Every new name is a framework idiom: middleware `handle` and `terminate`, Inertia's `share`, a Laravel cache-store adapter, and `LocaleFormatter::canonicalize` for Laravel's own locale store. That last one is the arguable behaviour name; it touches no SDK boundary (WIRE-3). |
| GRANT-1 | n/a (profile: browser) | - | A server binding holds a write key, and a server SDK must not send `X-Write-Grant`. This package sets no request header of any kind: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `x-write-grant`. Core evidence: `tests/Http/HttpClientTest.php::testNoWriteGrantHeaderIsSent`. |
| GRANT-2 | n/a (profile: browser) | - | As GRANT-1. |
| GRANT-3 | n/a (profile: browser) | - | As GRANT-1. |
| GRANT-4 | n/a (profile: browser) | - | As GRANT-1. |
| CACHE-1 | implemented | n/a (pure) | **The keys this package writes itself are now scoped to the project.** `LaravelCacheAdapter` kept one key index per store and prefix, so clearing one project's cache evicted another's catalog. The index is now scoped by project id: `LaravelCacheAdapterTest::testClearingOneProjectLeavesAnotherProjectsKeys`, and on the route an application takes, `ServiceProviderTest::testTheProviderScopesTheCacheIndexToTheProject`. M11. **Reviewer finding 2's key is gone, pending the operator's ruling on the removal:** the page cache was keyed by locale and HTML, with no project id, and it is removed (BIND-5). The core's own keys pass through verbatim with the core's namespacing, core `CACHE-1`: provisional. An adapter constructed by hand without `$projectId` keeps an unscoped index. The service provider never builds one that way, and the constructor documents why the id must be passed. |
| OBS-1 | delegated | - | Core `OBS-1`: **not implemented**. Emitting this needs the capability the binding may not read (BIND-2): `BindingBoundaryTest::testTheBindingNeverTouchesServerComputedCapability`. **Counts red here while the core row is `not implemented`.** Gap 3. |
| WIRE-1 | delegated | - | Core `WIRE-1`: provisional. The binding sets no request header: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour` forbids `x-authorization` and transport. |
| WIRE-2 | delegated | - | Core `WIRE-2`: provisional. The binding parses no response: `BindingBoundaryTest::testTheBindingOwnsNoNetworkBehaviour`. |
| WIRE-3 | implemented | n/a (pure) | **Reviewer finding 6 was checked, and measurement inverts its premise.** The concern was the wrapper's canonical casing against the 838 core's lowercasing. But while `Client::setLocale()` normalizes, `Client::translate()` and `getTranslations()` key their catalog by the locale they are handed, verbatim: an `es-ES` lookup misses an `es-es` catalog and goes to the network. So the binding's lowercasing before those calls is load-bearing. `LangsysTranslatorTest::testEveryHostSpellingOfALocaleReadsTheSameCatalog` reads one catalog entry from `es-es`, `es-ES`, `es_ES` and `ES-es` on the real core, M6. Every other SDK boundary is lowercase too. `TranslateResponse` and `DetectLocale` go through `setLocale()`. The Inertia hand-off now sends `es-es` instead of `es-ES` — a change pending the operator's ruling — which is the form the JS core on 838 canonicalizes to anyway: `InertiaSsrPropsTest::testEveryHostSpellingHandsTheSdkForm`, M15. Canonical BCP 47 remains only in Laravel's own `app()->getLocale()` — the host locale store this rule describes. For categories, the binding passes `null` and never spells `__uncategorized__`, M7c. The wire is the core's, core `WIRE-3`: provisional; its non-normalizing entry points are raised below. |
| WIRE-4 | implemented | n/a (pure) | Proven on the real core with the API genuinely unreachable — a refused connection to a closed local port, not a double that always answers — on every entry point this package exposes. `t()` renders the interpolated source phrase and queues nothing, `LangsysTranslatorTest::testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing`, M21. `TranslateResponse` serves the untranslated page, `TranslateResponseTest::testAnUnreachableApiServesTheUntranslatedPage`. A boundary flush fails nothing, `RequestScopeTest::testAFlushThatCannotReachTheApiFailsNothing`. `InertiaSsrProps::share()` hands no seed instead of a 500, `InertiaSsrPropsTest::testAnUnreachableApiHandsNoSeedInsteadOfThrowing`, M13. That last one was a defect this exercise found. **Reviewer finding 5, closed:** wherever the core already degrades, the binding's own catch is removed. `testDoesNotSwallowAFailureTheSdkLetThrough`, in both `LangsysTranslatorTest` and `TranslateResponseTest`, pins that neither comes back, M7b, M9. |
| WIRE-5 | delegated | - | Core `WIRE-5`: provisional, proven against a real loopback double. This package maps it onto `langsys.api_url` and `LANGSYS_API_URL`, documented in the README's configuration section. `ServiceProviderTest::testTheConfiguredApiUrlIsWhereTheSdkConnects` observes the configured address in a real connection attempt. The ordering half, `::testAnApiUrlChangedAfterTheClientIsBuiltIsNotUsed`, shows that a change made after the client is built reaches nothing. M20. |
| CONF-1 | provisional | mock | Tests assert an observable consequence wherever this package's evidence allows: the next unit of work's state, the served bytes, the core's own pending queue. No test can assert on the server's acceptance until the shared contract fixture exists, and the API-dependent rows are `provisional` for that reason. **Every path:** rows name each Laravel route a rule was proven on — PHP-FPM, Octane, queue job finished and thrown, `t()` and `translatePage()`. The core grades this row `not implemented`. Here, the evidence that exists meets the every-path clause and waits only on the fixture. Waits on: CONF-2 shared contract fixture. |
| CONF-2 | implemented | n/a (pure) | Every row carries exactly one status and one tier, and the pairing is checked mechanically. The script under *Computed summary* exits non-zero on any missing, duplicated or unknown rule id, and on an unrecognised status or tier. It also rejects an `implemented` row whose tier is not `live`, `contract` or `n/a (pure)`, and a `provisional` row whose tier is not `mock`. A `delegated` row must carry tier `-`, and the summary resolves it against the core's current grade in `../langsys-php-sdk/CONFORMANCE.md`, as the fleet checker does. |
| CONF-3 | implemented | n/a (pure) | Every guard this package owns was mutated in place, and every mutant reddened a named test; see *Mutation record*. Sources were verified restored byte for byte after each run. There are no SSR strategy cases here to isolate. Each test builds a fresh application and binds its own `Client`, and the Octane leg's stand-in event is recorded under GATE-3. |

## Pending the operator's ruling

These three changes are in the code at this tip, but the operator holds the sign-off; none is accepted. Each rests on a binding rule, and what each row becomes if the change is reverted is stated here in advance, so a reversal regrades the rows instead of silently invalidating them. The restore paths are in `ROADMAP.md`.

- **Removal of `auto_flush`.** Rests on it: BIND-4. **If restored:** BIND-4 is no longer green unless the operator records a waiver, and `BindingBoundaryTest::testEveryConfigKeyIsACoreOptionOrLaravelWiring` gains the key. Under PHP-FPM the setting would still not stop a flush, because the core's shutdown handler sends the queue regardless. It must never gate the long-lived reset, or GATE-3 and SRV-2 regress.
- **Removal of the `TranslateResponse` page cache.** Rests on it: BIND-5, CACHE-1, and the page-cache sentences in GATE-4, GATE-7 and BIND-1. **If restored as a waiver:** BIND-5 becomes `waived`, citing the operator's recorded agreement. The restored key must carry the project id or CACHE-1 fails. GATE-7 regains a recorded limit — a cache hit feeds no lane — and `TranslateResponseTest::testEveryRequestReachesTheSdk` narrows to the cache-disabled case, M10 no longer applying.
- **Lowercase `initialTranslationsLocale`.** Rests on it: WIRE-3 and SRV-4. **If `es-ES` is kept:** both stay green, because the 838 JS core canonicalizes either spelling on receipt and the hand-off's identity holds. But WIRE-3's "every SDK boundary is lowercase" narrows to "every PHP-core boundary", and M15's three tests revert to canonical expectations.

## Gaps, ranked by cost

Ranked by what each gap costs someone running the Laravel stack, not by rule order. Items 1 to 5 are the core's to close; this package delegates them by rule.

1. **REG-8 — no backoff, and long-lived workers amplify it.** The core retains a failed send, and `resetRequestState()` does not clear the retained queue. Under Octane or a queue worker, a failing registration endpoint therefore gets a POST at every request or job, with a payload that grows as new misses join it. Load, exactly on the busiest workers.
2. **The core logs to a `NullLogger` by default (REG-10, and REG-3 per the spec's Open section).** In a default Laravel app, a degraded lookup, a dropped flush and a failed send are all recorded nowhere. Diagnosability. Routing the core's logger to a Laravel channel is deferred in `ROADMAP.md`, because it changes log volume.
3. **OBS-1 — a misconfigured key is completely silent.** Nothing is queued, nothing errors, nothing logs.
4. **REG-11 — no ellipsis diagnostic.** Catalog pollution and double translation spend.
5. **WIRE-3 in the core's entry points.** This package compensates, but `Langsys::client()->translate('Save', 'es-ES')` — the documented escape hatch — splits the catalog cache and fetches twice.
6. **CONF-1 / CONF-2 — the shared contract fixture.** Until it exists, SRV-1, SRV-3 and CONF-1 stay `provisional`.

## Release gate

- **This tip needs the 838 core, and the core is untagged** (Reviewer finding 8). The code calls `resetRequestState()` and relies on `translate()` and `translatePage()` never throwing. v1.3.1 has none of that, and `^1.3` still resolves to v1.3.1 from Packagist. So CI — which installs from Packagist — fails on this branch by design, and **the suite no longer passes against v1.3.1**, where it used to (Reviewer finding 9). At publication, the constraint moves to the core's 838 tag.
- **The local path repository comes out at publication.** Locally, `composer.json` carries an uncommitted path repository to `../langsys-php-sdk` — a standing exception in the end-of-day sweep, recorded in `ROADMAP.md` — so the tree is clean apart from that one file.

## Mutation record

Each mutant was applied in place with a single exact-match substitution, run against the named filter, and reverted. Sources were then hash-checked against their originals. **No mutant survived.**

| Mutant | What it breaks | Reddened |
|---|---|---|
| M1 | boundary never calls `resetRequestState()` | `RequestScopeTest`: `testEveryBoundaryFlushesAndThenResets`, `testTheNextUnitOfWorkReadsTheCatalogAfresh`, `testTheNextUnitOfWorkStartsWithoutAWriteDecision` |
| M2 | reset before flush | `testEveryBoundaryFlushesAndThenResets` |
| M3 | boundary builds a `Client` nobody used | `testABoundaryNeverBuildsAClientNobodyUsed` |
| M4 | a job that threw is not a boundary | the three `RequestScopeTest` cases in M1 |
| M5 | an Octane request is not a boundary | the three `RequestScopeTest` cases in M1 |
| M6 | translator stops normalizing the locale | `testEveryHostSpellingOfALocaleReadsTheSameCatalog`, `testAnUncategorizedPhraseResolvesThroughTheSdksOwnNamespace`, `testReturnsExactlyWhatTheSdkReturned` |
| M7a | translator re-interpolates the SDK's result | `testReturnsExactlyWhatTheSdkReturned` |
| M7b | translator re-adds its own catch | `LangsysTranslatorTest::testDoesNotSwallowAFailureTheSdkLetThrough` |
| M7c | translator spells `__uncategorized__` | `testTheBindingReimplementsNoIdentityOrRenderingBehaviour` |
| M8 | flush moved onto the request path | `testTheFlushRunsOnlyOnceTheResponseExists` |
| M9 | middleware re-adds its own catch | `TranslateResponseTest::testDoesNotSwallowAFailureTheSdkLetThrough` |
| M10 | middleware memoizes translated pages | `testEveryRequestReachesTheSdk` |
| M11 | cache index unscoped by project | `testClearingOneProjectLeavesAnotherProjectsKeys`, `testTheProviderScopesTheCacheIndexToTheProject` |
| M12 | adapter TTL defaults back to 3600 | `testTheConfiguredTtlAppliesWhenTheSdkPassesNone` |
| M13 | Inertia hand-off loses its catch | `testAnUnreachableApiHandsNoSeedInsteadOfThrowing` |
| M14 | Inertia hand-off re-reads the shared cache | `testHandsTheClientTheCatalogThisRequestRenderedWith` |
| M15 | Inertia hand-off canonical-cased again | `testBuildsTheJsSdkSeedingShape`, `testEveryHostSpellingHandsTheSdkForm`, `testExplicitLocaleOverridesTheAppLocale`, `testHandsTheClientTheCatalogThisRequestRenderedWith` |
| M16a | `canWrite()` planted in the translator | `testTheBindingNeverTouchesServerComputedCapability` |
| M16b | `md5()` planted in the middleware | `testTheBindingReimplementsNoIdentityOrRenderingBehaviour` |
| M16c | `usleep()` planted in `terminate()` | `testTheBindingOwnsNoNetworkBehaviour` |
| M17 | `auto_flush` re-added to config | `testEveryConfigKeyIsACoreOptionOrLaravelWiring` |
| M18 | a new public method on `InertiaSsrProps` | `testThePublicSurfaceIsTheOneArguedForHere` |
| M19 | `DetectLocale` never sets the app locale | `testTheServedBytesCarryTheRequestLocalesTranslations` |
| M20 | provider ignores `langsys.api_url` | `testTheConfiguredApiUrlIsWhereTheSdkConnects`, `testAnApiUrlChangedAfterTheClientIsBuiltIsNotUsed` |
| M21 | translator throws where the SDK degrades | `testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing` |

M20 substitutes a second closed port rather than removing the setting: removing it would have pointed a mutant at the production API.

## Raised against the core and the spec

These are not findings against this package. They were measured here and raised through the Reviewer.

- **Core WIRE-3: `Client::translate()` and `getTranslations()` do not normalize the locale they are handed.** Reproduction: cache an `es-es` catalog, call `translate('Save', 'es-ES')`. The core reads `translations_<project>_es-ES`, misses, and goes to the network. Only `setLocale()` normalizes.
- **Core HINT-2 is mis-graded.** It is rowed `n/a (profile: server)` inside the `HINT-1 … HINT-8` range, but HINT-2's profile is `server`. Non-participation is testable, as it is for GRANT's `X-Write-Grant` clause.
- **Core REG-10: the default `NullLogger`.** "Always log" is unmet in any integration that does not enable core logging, and that includes a default Laravel app.
- **Delegated rows that rest on `not implemented` core rows — settled.** REG-8, REG-11 and OBS-1 rest on such rows. The Reviewer confirmed that the fleet checker resolves a delegated row against the core's current grade, so these count red here until the core's rows turn green. The summary below resolves them the same way.
- **Spec SRV-2:** its test calls sequential renders proof of nothing, but in a per-request runtime the interleave is unreachable, and sequential reuse across a boundary is the failure that exists. It is worth asking whether the test wording should admit that.

## Computed summary

Produced by the script below, run from the repository root with `langsys2` and `langsys-php-sdk` checked out alongside. It exits non-zero on a malformed file. A red grade is a fact about the SDK, not a defect in this file, so it is reported rather than failed on.

```
spec blob, re-derived             5c5c0723f88fb8e6b13f58876c7adca8b6b35691
rule ids in the spec              79
rowed exactly once                79
missing / duplicated / unknown    0 / 0 / 0
malformed rows                    0
as graded in this file
  implemented                     15
  delegated                       37
  n/a                             24
  provisional                     3
  waived                          0
  partial                         0
  not implemented                 0
  held (strip ruling)             0
delegated rows resolved against langsys-php-sdk 5400248
  n/a                             1
  not implemented                 3
  provisional                     33
counting red                      REG-8, REG-11, OBS-1
GREEN, provisional counted apart  no
```

```python
import collections, re, subprocess, sys

T = '5cff03a17751e7dae9dcf1af52a9454d027c9006'
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
    if status == 'provisional' and (tier != 'mock' or 'Waits on: CONF-2 shared contract fixture' not in evidence):
        bad.append((rid, 'provisional without mock and its waits-on clause'))
    if not evidence:
        bad.append((rid, 'no evidence'))
    if status == 'delegated' and tier != '-':
        bad.append((rid, 'delegated without tier -'))

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
rtally = collections.Counter(resolved.values())
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
