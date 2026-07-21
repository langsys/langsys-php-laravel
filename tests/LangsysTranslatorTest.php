<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Interpolator;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\FakeClient;

class LangsysTranslatorTest extends TestCase
{
    /**
     * The API returns null for a phrase that exists in the project but has no
     * translation yet, and php-sdk builds without the null guard pass it
     * through (their empty check is `$value !== ''`, which null passes).
     * The translator must fall back to the phrase, not throw a TypeError.
     */
    private function clientReturningNull(): FakeClient
    {
        return new class extends FakeClient {
            public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null)
            {
                return null;
            }
        };
    }

    public function testFallsBackToThePhraseWhenTheClientReturnsNull(): void
    {
        $translator = new LangsysTranslator($this->clientReturningNull(), new Interpolator());

        $this->assertSame('Welcome', $translator->translate('Welcome', null, [], 'es-ES'));
    }

    public function testInterpolatesTheFallbackWhenTheClientReturnsNull(): void
    {
        $translator = new LangsysTranslator($this->clientReturningNull(), new Interpolator());

        $this->assertSame(
            'Welcome Sarah',
            $translator->translate('Welcome {name}', 'Home', ['name' => 'Sarah'], 'es-ES')
        );
    }
}
