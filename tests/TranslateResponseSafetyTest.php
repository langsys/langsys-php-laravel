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
 * Livewire/Alpine init payloads. A page translator that encoded a `<script>`
 * body into a registered phrase would let a catalog entry rewrite inline JS
 * back into the page, a stored-XSS shape. A test over
 * an HTML fragment would not exercise any of that, so this one asserts against
 * markup with the things that make a real page dangerous.
 *
 * Only getTranslations(), getLocale(), canWrite() and the flush are stubbed, to
 * keep the test off the network; every parsing, walking and registration
 * decision below is upstream's own code.
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
             * The SDK registers a shutdown handler that flushes whatever is
             * still queued. These tests deliberately leave phrases queued —
             * that IS the assertion — so without this the suite would POST
             * them for real at process exit. Harmless against a fake key;
             * against a developer's real credentials it would register test
             * fixtures into a live catalog shared by every Langsys SDK.
             */
            public function flushPendingRegistrations()
            {
                return ['phrases' => 0, 'content_blocks' => 0, 'success' => true];
            }
        };
    }

    /**
     * What actually reaches the shared catalog. translatePage() queues what it
     * would register, and FlushPendingRegistrations drains the queue after the
     * response, so the pending queue, not any transport call, is what decides
     * what gets registered. It is read directly rather than through an
     * overridden method, so a change to the queue API fails here rather than
     * passing vacuously.
     *
     * @return list<string>
     */
    private function queuedPhrases(Client $client): array
    {
        return array_column($client->getPendingPhrases(), 'phrase');
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

    /**
     * Markup written inside a script or style body is text, not tags: the HTML a script assigns, a
     * template literal, JSON-LD and a CSS comment all come back byte for byte, so a translated page
     * runs the same code.
     */
    public function testMarkupInsideAScriptOrStyleBodySurvives(): void
    {
        $bodies = [
            "el.innerHTML = '<p>hi</p>';",
            'const row = `<div><span>${n}</span></div>`;',
            '{"@type":"Product","description":"<b>bold</b>"}',
            '/* </p> */ .card > p { margin: 0; }',
        ];
        $page = '<html><head><style>' . $bodies[3] . '</style>'
            . '<script type="application/ld+json">' . $bodies[2] . '</script></head>'
            . '<body><h1>Save</h1><script>' . $bodies[0] . ' ' . $bodies[1] . '</script></body></html>';

        $html = $this->realClient()->translatePage($page);

        $this->assertStringContainsString('<h1>Guardar</h1>', $html, 'Control: the page was walked.');
        foreach ($bodies as $body) {
            $this->assertStringContainsString($body, $html);
        }
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

        $queued = $this->queuedPhrases($client);

        // Guard the guard: if the walker queued nothing at all, the loop below
        // would pass vacuously and this test would prove nothing.
        $this->assertContains(
            'Continue to checkout',
            $queued,
            'Expected the untranslated heading to be queued — otherwise this test is vacuous.'
        );

        foreach ($queued as $phrase) {
            foreach (['window.', 'fetch(', 'csrf', 'addEventListener', '::after', 'tok_A1B2C3'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $phrase,
                    "Queued a phrase carrying script/style content: {$phrase}"
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
     * Presence is intent: a bare `data-notrans`, an empty value or "true"
     * excludes, and only "false" or "0", trimmed and in any case, opts back in.
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
            // Control: an unmarked subtree MUST be queued. This is what proves
            // the capture point is live, so the exclusions above are absences
            // of something that would otherwise be there.
            'class="plain"'          => false,
        ];

        foreach ($markers as $attribute => $shouldExclude) {
            $client = $this->realClient();
            $client->translatePage("<html><body><p {$attribute}>Sensitive copy</p></body></html>");

            $queued = $this->queuedPhrases($client);

            $shouldExclude
                ? $this->assertNotContains('Sensitive copy', $queued, "[{$attribute}] leaked into the shared catalog.")
                : $this->assertContains('Sensitive copy', $queued, "[{$attribute}] should not have excluded the subtree.");
        }
    }

    /** The documented escape hatch for already-resolved subtrees. */
    public function testTranslateNoSubtreeIsLeftAlone(): void
    {
        $client = $this->realClient();
        $html = $client->translatePage(self::PAGE);

        $this->assertStringContainsString('<p translate="no">Guardar</p>', $html);

        $queued = $this->queuedPhrases($client);

        // Positive assertion first, so the absence below can't pass against an
        // empty queue that never captured anything.
        $this->assertContains('Continue to checkout', $queued, 'Capture point is not live.');
        $this->assertNotContains('Guardar', $queued, 'A translate="no" subtree must not be queued as a source phrase.');
    }
}
