<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Facades\Langsys;
use Langsys\Laravel\Http\Middleware\DetectLocale;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Client;

/**
 * The facade gets its own file because its failure mode is silent at every
 * layer this repo has. `getFacadeAccessor()` returning a wrong
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
     * `Welcome {name}` is looked up, one catalog entry for every runtime
     * value, and its translation filled. Interpolated wrapper-side, the
     * lookup would be `Welcome Sarah`, which no catalog holds.
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

        $client->seed('es-ES', 'Home', ['Welcome {name}' => 'Bienvenido, {name}']);

        $this->assertSame('Bienvenido, Sarah', Langsys::translate('Welcome {name}', 'Home', ['name' => 'Sarah'], 'es-ES'));
        $this->assertSame(['name' => 'Sarah'], $client->receivedParams);
        $this->assertSame(['Welcome {name}'], $client->asked, 'The placeholder-bearing phrase is the lookup, not the interpolated string.');
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

    /**
     * The core's reads take the locale this request is served in, not the core's own guess from
     * $_SERVER, and hand back exactly what the core returned. The core reads every spelling.
     */
    public function testGetCurrenciesReadsTheCoresListInLaravelsLocale(): void
    {
        $client = $this->_readsDouble();
        $this->app->setLocale('es_ES');

        $this->assertSame([['read' => 'getCurrencies']], Langsys::getCurrencies());
        $this->assertSame([['getCurrencies', ['es_ES']]], $client->reads);
    }

    /** An empty locale is no locale, as in Laravel: the read is in the current one. */
    public function testAnEmptyLocaleReadsInLaravelsLocale(): void
    {
        $client = $this->_readsDouble();
        $this->app->setLocale('es_ES');

        Langsys::getCountries('');
        $this->assertSame([['getCountries', ['es_ES']]], $client->reads);
    }

    public function testGetCountriesReadsTheCoresListInTheLocaleItIsGiven(): void
    {
        $client = $this->_readsDouble();

        $this->assertSame([['read' => 'getCountries']], Langsys::getCountries('pt-BR'));
        $this->assertSame([['getCountries', ['pt-BR']]], $client->reads);
    }

    /** A bare `es` the app set is served as the project's default Spanish (SRV-6), and read in it. */
    public function testGetDialCodesReadsTheCoresListInTheServedLocale(): void
    {
        $client = $this->_readsDouble();
        $this->app->setLocale('es');
        request()->attributes->set(DetectLocale::SERVED, ['es', 'es-mx']);

        $this->assertSame([['read' => 'getDialCodes']], Langsys::getDialCodes());
        $this->assertSame([['getDialCodes', ['es-mx']]], $client->reads);
    }

    public function testGetLocalesFormatPassesTheFormatAndLaravelsLocale(): void
    {
        $client = $this->_readsDouble();
        $this->app->setLocale('de-DE');

        $this->assertSame([['read' => 'getLocalesFormat']], Langsys::getLocalesFormat('flat'));
        $this->assertSame([['getLocalesFormat', ['flat', 'de-DE']]], $client->reads);
    }

    /** A client whose reads record what they were handed and answer with a list naming the read. */
    private function _readsDouble(): FakeClient
    {
        $client = new class extends FakeClient {
            public array $reads = [];

            public function getCurrencies($locale = null)
            {
                return $this->_read(__FUNCTION__, func_get_args());
            }

            public function getCountries($locale = null)
            {
                return $this->_read(__FUNCTION__, func_get_args());
            }

            public function getDialCodes($locale = null)
            {
                return $this->_read(__FUNCTION__, func_get_args());
            }

            public function getLocalesFormat($format = '', $locale = null)
            {
                return $this->_read(__FUNCTION__, func_get_args());
            }

            private function _read(string $method, array $args): array
            {
                $this->reads[] = [$method, $args];

                return [['read' => $method]];
            }
        };

        $this->app->instance(Client::class, $client);
        $this->app->forgetInstance(LangsysTranslator::class);
        Langsys::clearResolvedInstances();

        return $client;
    }
}
