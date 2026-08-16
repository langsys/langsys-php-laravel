<?php

namespace Langsys\Laravel\Tests;

use Langsys\SDK\Client;

/**
 * Runs the REAL page translator — not the FakeClient stand-in — over a
 * Laravel-shaped response, because this is the one risk the wrapper creates
 * rather than inherits.
 *
 * `@t` hands the SDK a single phrase. This middleware hands it a whole rendered
 * response, which is where Blade puts CSRF tokens, bootstrapped JSON and
 * Livewire/Alpine init payloads. Upstream caught (pre-release) a bug that
 * encoded `<script>` bodies into registered phrases — meaning a catalog entry
 * could rewrite inline JS back into the page, a stored-XSS shape. A test over
 * an HTML fragment would not exercise any of that, so this one asserts against
 * markup with the things that make a real page dangerous.
 *
 * Only getTranslations() is stubbed, to keep the test off the network; every
 * parsing, walking and registration decision below is upstream's own code.
 */
class TranslateResponseSafetyTest extends TestCase
{
    private const PAGE = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="csrf-token" content="tok_A1B2C3">
    <title>Save</title>
    <style>.btn::after { content: "Save"; }</style>
</head>
<body>
    <h1>Save</h1>
    <h2>Continue to checkout</h2>
    <p translate="no">Guardar</p>
    <script>
        window.__INITIAL_STATE__ = {"csrf":"tok_A1B2C3","label":"Save"};
        document.addEventListener('click', () => fetch('/api/save'));
    </script>
</body>
</html>
HTML;

    private function realClient(): Client
    {
        return new class ('test-key', 'test-project', ['cache_driver' => 'none']) extends Client {
            /** @var list<array{phrase: string, category: string}> */
            public array $registered = [];

            public function getLocale()
            {
                return 'es-es';
            }

            public function getTranslations($locale, $useCache = true)
            {
                return ['__uncategorized__' => ['Save' => 'Guardar']];
            }

            /** Write-key branch, so discovery actually runs. Stubbed to stay off the network. */
            public function canWrite()
            {
                return true;
            }

            /**
             * translatePage() registers through this public method directly,
             * NOT through the pending queue that translate() uses — so the
             * FlushPendingRegistrations middleware does not govern page
             * registrations. See ROADMAP.
             *
             * UPGRADE HOOK: fixed upstream but not yet tagged. Once a release
             * past v1.2.0 lands, PageTranslator calls
             * queuePhraseForRegistration() instead and this override captures
             * NOTHING — at which point the assertNotContains exclusion cases
             * below pass vacuously. Re-point this hook, don't just fix the
             * loud failures. See the upgrade checklist in ROADMAP.md.
             */
            public function registerPhrases(array $phrases)
            {
                foreach ($phrases as $phrase) {
                    $this->registered[] = $phrase;
                }

                return ['success' => true];
            }
        };
    }

    private function translatedPage(): string
    {
        return $this->realClient()->translatePage(self::PAGE);
    }

    /** Sanity: the walker is actually running, so the assertions below mean something. */
    public function testOrdinaryTextIsTranslated(): void
    {
        $this->assertStringContainsString('<h1>Guardar</h1>', $this->translatedPage());
    }

    /**
     * The script body must survive byte-for-byte. If a catalog entry could
     * rewrite inline JS, a translator with catalog access would have script
     * injection into every page rendered through this middleware.
     */
    public function testInlineScriptBodyIsPreservedVerbatim(): void
    {
        $html = $this->translatedPage();

        $this->assertStringContainsString('window.__INITIAL_STATE__ = {"csrf":"tok_A1B2C3","label":"Save"};', $html);
        $this->assertStringContainsString("fetch('/api/save')", $html);
    }

    /** A rewritten CSRF token would break every form on the page. */
    public function testCsrfTokensSurviveInMetaAndScript(): void
    {
        $html = $this->translatedPage();

        $this->assertSame(
            2,
            substr_count($html, 'tok_A1B2C3'),
            'Both the meta tag and the bootstrapped JSON token must be intact.'
        );
    }

    public function testInlineStyleBodyIsPreservedVerbatim(): void
    {
        $this->assertStringContainsString('content: "Save";', $this->translatedPage());
    }

    /**
     * The catalog is shared with every other Langsys SDK, so a JS fragment
     * registered as a source phrase would surface in translators' queues.
     */
    public function testNothingFromScriptOrStyleIsRegistered(): void
    {
        $client = $this->realClient();
        $client->translatePage(self::PAGE);

        // Guard the guard: if the walker registered nothing at all, the loop
        // below would pass vacuously and this test would prove nothing.
        $this->assertContains(
            'Continue to checkout',
            array_column($client->registered, 'phrase'),
            'Expected the untranslated heading to be registered — otherwise this test is vacuous.'
        );

        foreach ($client->registered as $entry) {
            foreach (['window.', 'fetch(', 'csrf', 'addEventListener', '::after', 'tok_A1B2C3'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $entry['phrase'],
                    "Registered a phrase carrying script/style content: {$entry['phrase']}"
                );
            }
        }
    }

    /**
     * Exclusion markers, asserted on the REGISTRATION LIST rather than the
     * rendered page. An exclusion bug reaches the shared catalog long before
     * anything looks wrong on screen, and catalog pollution is the
     * irreversible half — a page renders again next request, a bad phrase has
     * to be hunted down across every SDK reading that catalog.
     *
     * These pin semantics that were inverted as recently as v1.1.0, where bare
     * `data-notrans` LEAKED and `="false"` excluded, so there was no string an
     * author could write to opt back in. Fixed in v1.2.0: presence is intent,
     * only "false"/"0" opts out, trimmed and case-insensitive.
     */
    public function testExclusionMarkersAreHonouredOnRegistration(): void
    {
        $markers = [
            // attribute            => excluded from registration?
            'translate="no"'         => true,
            'translate="NO"'         => true,
            'data-notrans'           => true,
            'data-notrans=""'        => true,
            'data-notrans="true"'    => true,
            'data-notrans="false"'   => false,
            'data-notrans="0"'       => false,
            'data-notrans="FALSE"'   => false,
            'data-notrans=" false "' => false,
            'class="plain"'          => false,
        ];

        foreach ($markers as $attribute => $shouldExclude) {
            $client = $this->realClient();
            $client->translatePage("<html><body><p {$attribute}>Sensitive copy</p></body></html>");

            $registered = array_column($client->registered, 'phrase');

            $shouldExclude
                ? $this->assertNotContains('Sensitive copy', $registered, "[{$attribute}] leaked into the shared catalog.")
                : $this->assertContains('Sensitive copy', $registered, "[{$attribute}] should not have excluded the subtree.");
        }
    }

    /** The documented escape hatch for already-resolved subtrees. */
    public function testTranslateNoSubtreeIsLeftAlone(): void
    {
        $client = $this->realClient();
        $html = $client->translatePage(self::PAGE);

        $this->assertStringContainsString('<p translate="no">Guardar</p>', $html);
        $this->assertSame(
            [],
            array_filter($client->registered, fn (array $e) => $e['phrase'] === 'Guardar'),
            'A translate="no" subtree must not be registered as a source phrase.'
        );
    }
}
