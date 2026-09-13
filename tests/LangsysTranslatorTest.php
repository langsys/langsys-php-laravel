<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Exception\ApiException;

/**
 * The translator chooses a locale and hands everything else to the SDK. These
 * hold it to that: what the SDK returns is what the caller gets, and a failure
 * the SDK lets through is not quietly repaired here. The SDK's own fallback
 * (WIRE-4, CAT-2) is the one the fleet tests; a second copy in this class
 * could only drift from it.
 */
class LangsysTranslatorTest extends TestCase
{
    /**
     * A result no fallback could produce. It carries the phrase's raw
     * `{name}`, so a translator that substituted the source phrase,
     * interpolated the result or trimmed it would not hand it back intact.
     */
    public function testReturnsExactlyWhatTheSdkReturned(): void
    {
        $client = new class extends FakeClient {
            public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null, array $params = [])
            {
                return "\u{2063}sdk:{$phrase}:{$locale}\u{2063}";
            }
        };

        $this->assertSame(
            "\u{2063}sdk:Welcome {name}:es-es\u{2063}",
            (new LangsysTranslator($client))->translate('Welcome {name}', 'Home', ['name' => 'Sarah'], 'es-ES')
        );
    }

    /** The SDK does not throw from translate(). If something does, this class must not be the layer that hides it. */
    public function testDoesNotSwallowAFailureTheSdkLetThrough(): void
    {
        $client = new class extends FakeClient {
            public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null, array $params = [])
            {
                throw new ApiException('Invalid request', 404);
            }
        };

        $this->expectException(ApiException::class);

        (new LangsysTranslator($client))->translate('Welcome', null, [], 'es-ES');
    }

    /**
     * WIRE-3, observed on the real SDK: Client::translate() keys its catalog by
     * the locale string it is handed, verbatim, so an `es-ES` lookup misses an
     * `es-es` catalog and goes to the network. Every host spelling must reach
     * the one entry.
     */
    public function testEveryHostSpellingOfALocaleReadsTheSameCatalog(): void
    {
        $translator = new LangsysTranslator($this->offlineClient(['es-es' => ['UI' => ['Save' => 'Guardar']]]));

        foreach (['es-es', 'es-ES', 'es_ES', 'ES-es'] as $hostLocale) {
            $this->assertSame('Guardar', $translator->translate('Save', 'UI', [], $hostLocale), "Host locale {$hostLocale} missed the catalog.");
        }
    }

    /**
     * No-category is the SDK's to name — `__uncategorized__` is its internal
     * lookup namespace (WIRE-3, CID-2), so this package never spells it.
     * Observed through the real SDK rather than asserted on what was passed.
     */
    public function testAnUncategorizedPhraseResolvesThroughTheSdksOwnNamespace(): void
    {
        $translator = new LangsysTranslator($this->offlineClient(['es-es' => ['__uncategorized__' => ['Save' => 'Guardar']]]));

        $this->assertSame('Guardar', $translator->translate('Save', null, [], 'es-ES'));
    }

    /**
     * Params must reach the SDK rather than being applied to the returned
     * string afterwards: the SDK queues the raw placeholder-bearing phrase for
     * registration and interpolates only what it hands back. Applying params
     * wrapper-side would still render correctly here, so assert on what the
     * SDK actually received.
     */
    public function testPassesParamsThroughToTheSdkSoTheRawPhraseIsRegistered(): void
    {
        $client = new class extends FakeClient {
            public array $receivedParams = [];

            public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null, array $params = [])
            {
                $this->receivedParams = $params;

                return parent::translate($phrase, $locale, $category, $contentBlockId, $params);
            }
        };

        $translator = new LangsysTranslator($client);
        $translator->translate('Welcome {name}', 'Home', ['name' => 'Sarah'], 'es-ES');

        $this->assertSame(['name' => 'Sarah'], $client->receivedParams);
        $this->assertSame(
            [['phrase' => 'Welcome {name}', 'category' => 'Home']],
            $client->queuedPhrases,
            'The catalog must receive the placeholder-bearing phrase, not the interpolated string.'
        );
    }

    /**
     * WIRE-4 on the real SDK: with nothing cached and the API unreachable, the
     * phrase renders in the base language with its params applied, and nothing
     * is queued — a failed catalog fetch cannot tell a miss from a hit.
     */
    public function testAnUnreachableApiRendersTheSourcePhraseAndQueuesNothing(): void
    {
        $client = $this->offlineClient();

        $this->assertSame('Welcome Sarah', (new LangsysTranslator($client))->translate('Welcome {name}', 'Home', ['name' => 'Sarah'], 'es-ES'));
        $this->assertSame([], $client->getPendingPhrases());
    }
}
