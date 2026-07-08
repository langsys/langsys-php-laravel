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

    public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null)
    {
        $locale = $locale !== null ? LocaleDetector::normalize($locale) : $this->getLocale();

        if ($locale === null) {
            return $phrase;
        }

        $translation = $this->seededTranslations[$locale][$category][$phrase] ?? null;

        if ($translation !== null && $translation !== '') {
            return $translation;
        }

        $this->queuedPhrases[] = ['phrase' => $phrase, 'category' => $category];

        return $phrase;
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
