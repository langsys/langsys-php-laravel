<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Facades\Langsys;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Client;

/**
 * The facade had NO test until this file, and the gap was invisible in the
 * usual ways: a probe diffing get_included_files() against src/ after a full
 * green run found `src/Facades/Langsys.php` was the one source file no test
 * caused PHP to compile. Grepping for the class name reported thirteen hits —
 * all of them the `Langsys\Laravel\…` namespace, none of them the facade.
 *
 * What makes an untested facade worth its own file is that its failure mode is
 * silent at every layer this repo has. `getFacadeAccessor()` returning a wrong
 * or stale binding is valid PHP: it parses clean, so the CI lint job passes; it
 * breaks no other test, so the suite stays green; and the `@method` docblock
 * that documents the API is a comment, enforced by nothing (there is no static
 * analysis configured here). The first thing to notice would be a consumer's
 * call to `Langsys::translate()` resolving the wrong service.
 *
 * So these assert the WIRING, not the translating — LangsysTranslatorTest owns
 * the behaviour. Each test below fails if the accessor stops pointing at the
 * binding the service provider registers.
 */
class FacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        // The facade caches its resolved root in a static, which survives
        // between tests in the same process and would let a later test pass
        // against an instance the container no longer holds.
        Langsys::clearResolvedInstances();

        parent::tearDown();
    }

    /**
     * The accessor names a class the provider binds as a singleton. If those
     * two drift apart — a rename, a moved namespace — the container still
     * resolves *something* (Laravel would build an unbound concrete class
     * automatically), so assert identity with the registered singleton rather
     * than merely asserting the type.
     */
    public function testTheFacadeResolvesToTheContainersTranslatorSingleton(): void
    {
        $this->assertSame(
            $this->app->make(LangsysTranslator::class),
            Langsys::getFacadeRoot(),
            'The facade accessor no longer points at the binding the service provider registers.'
        );
    }

    /**
     * The documented `Langsys::translate()` call has to reach the real
     * translator. Seeded rather than asserting the fallback, so a facade wired
     * to nothing useful cannot pass: the base-language phrase comes back
     * unchanged on every degraded path, making "Save" the one return value that
     * would prove nothing.
     */
    public function testTranslateReachesTheTranslator(): void
    {
        $this->fakeClient->seed('es-ES', 'UI', ['Save' => 'Guardar']);

        $this->assertSame('Guardar', Langsys::translate('Save', 'UI', [], 'es-ES'));
    }

    /**
     * The full documented signature, proven where it matters: params must
     * arrive at the SDK rather than being applied to the returned string, so
     * the catalog receives `Welcome {name}` instead of one entry per runtime
     * value. That contract is the reason the facade cannot just forward
     * loosely — assert on what the SDK received, since interpolating
     * wrapper-side would produce identical output here.
     */
    public function testTranslatePassesCategoryAndParamsThroughToTheSdk(): void
    {
        $client = new class extends FakeClient {
            public array $receivedParams = [];

            public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null, array $params = [])
            {
                $this->receivedParams = $params;

                return parent::translate($phrase, $locale, $category, $contentBlockId, $params);
            }
        };

        $this->app->instance(Client::class, $client);
        $this->app->forgetInstance(LangsysTranslator::class);
        Langsys::clearResolvedInstances();

        Langsys::translate('Welcome {name}', 'Home', ['name' => 'Sarah'], 'es-ES');

        $this->assertSame(['name' => 'Sarah'], $client->receivedParams);
        $this->assertSame(
            [['phrase' => 'Welcome {name}', 'category' => 'Home']],
            $client->queuedPhrases,
            'The catalog must receive the placeholder-bearing phrase, not the interpolated string.'
        );
    }

    /**
     * `Langsys::client()` is the documented escape hatch to the vanilla SDK
     * (README: "The facade and the raw client"). It must hand back the very
     * client the container holds — a fresh instance would silently have its own
     * empty registration queue, so phrases discovered through it would never
     * reach the flush the middleware performs after the response.
     */
    public function testClientReturnsTheContainersSdkClient(): void
    {
        $this->assertSame($this->fakeClient, Langsys::client());
    }
}
