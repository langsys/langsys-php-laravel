<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Interpolator;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Exception\ApiException;

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

    /**
     * An unreachable or refusing API (timeout, 404 on an unseeded project,
     * 401 on a revoked key) surfaces as a LangsysException from the SDK. The
     * translator must degrade to the base-language phrase — a translation
     * layer should never be the reason a page 500s.
     */
    private function clientThrowing(): FakeClient
    {
        return new class extends FakeClient {
            public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null)
            {
                throw new ApiException('Invalid request', 404);
            }
        };
    }

    public function testFallsBackToThePhraseWhenTheApiIsUnavailable(): void
    {
        $translator = new LangsysTranslator($this->clientThrowing(), new Interpolator());

        $this->assertSame('Welcome', $translator->translate('Welcome', null, [], 'es-ES'));
    }

    public function testInterpolatesTheFallbackWhenTheApiIsUnavailable(): void
    {
        $translator = new LangsysTranslator($this->clientThrowing(), new Interpolator());

        $this->assertSame(
            'Welcome Sarah',
            $translator->translate('Welcome {name}', 'Home', ['name' => 'Sarah'], 'es-ES')
        );
    }
}
