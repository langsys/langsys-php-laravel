<?php

namespace Langsys\Laravel\Tests\Fakes;

use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;

/**
 * In-memory Client double. The SDK's HTTP layer is hand-rolled cURL with no
 * injection seam, so tests bind this into the container instead of stubbing
 * HTTP. Seed translations per locale/category, then assert on the recorded
 * queue and flush calls.
 */
class FakeClient extends Client
{
    /** @var array<string, array<string, array<string, string>>> locale => category => phrase => translation */
    public array $seededTranslations = [];

    /** @var list<array{phrase: string, category: string}> */
    public array $queuedPhrases = [];

    /** @var list<array{html: string, category: ?string, locale: ?string}> */
    public array $translatedPages = [];

    public int $flushCalls = 0;

    public function __construct()
    {
        parent::__construct('test-key', 'test-project', [
            'cache_driver' => 'none',
        ]);
    }

    public function seed(string $locale, string $category, array $phrases): void
    {
        $locale = LocaleDetector::normalize($locale);

        foreach ($phrases as $phrase => $translation) {
            $this->seededTranslations[$locale][$category][$phrase] = $translation;
        }
    }

    /** The real getLocale() auto-detects from $_SERVER and can hit the API; keep the fake inert. */
    public function getLocale()
    {
        return $this->locale ?? null;
    }

    public function getTranslations($locale, $useCache = true)
    {
        return $this->seededTranslations[LocaleDetector::normalize($locale)] ?? [];
    }

    /**
     * Mirrors the real Client's contract, including the v1.0.0 $params
     * argument: registration queues the RAW placeholder-bearing phrase, and
     * only the returned string is interpolated — so one catalog entry serves
     * every runtime value. Interpolation itself runs through the inherited
     * SDK interpolator rather than a hand-rolled one, so this fake cannot
     * drift from the behavior it stands in for.
     */
    public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null, array $params = [])
    {
        $locale = $locale !== null ? LocaleDetector::normalize($locale) : $this->getLocale();

        if ($locale === null) {
            return $this->getInterpolator()->interpolate($phrase, $params, null);
        }

        $translation = $this->seededTranslations[$locale][$category][$phrase] ?? null;

        if ($translation !== null && $translation !== '') {
            return $this->getInterpolator()->interpolate($translation, $params, $locale);
        }

        $this->queuedPhrases[] = ['phrase' => $phrase, 'category' => $category];

        return $this->getInterpolator()->interpolate($phrase, $params, $locale);
    }

    /**
     * Records the call and performs a trivially observable substitution. The
     * real page translator walks the DOM, applies the translatable-attribute
     * config and tokenizes markup — all upstream's, and covered by upstream's
     * tests. What belongs here is the wrapper's decision: WHETHER we called it,
     * and WHAT we handed it.
     */
    public function translatePage($html, $category = null, array $selectorCategories = [], array $params = [])
    {
        $this->translatedPages[] = [
            'html'     => $html,
            'category' => $category,
            'locale'   => $this->getLocale(),
        ];

        return str_replace('Save', 'Guardar', $html);
    }

    public function hasPendingRegistrations()
    {
        return $this->queuedPhrases !== [];
    }

    public function flushPendingRegistrations()
    {
        $this->flushCalls++;
        $flushed = count($this->queuedPhrases);
        $this->queuedPhrases = [];

        return ['phrases' => $flushed, 'content_blocks' => 0, 'success' => true];
    }
}
