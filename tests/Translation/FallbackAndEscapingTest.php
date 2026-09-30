<?php

namespace Langsys\Laravel\Tests\Translation;

use Illuminate\Support\Facades\Blade;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;

/**
 * FRM-3: `__()` answers from the catalog, then the app's own lang files for the locale being
 * rendered, then the source. FRM-8: `@lang` and its alias `@t` print raw as Laravel's `@lang`
 * does, but a catalog translation can never add markup — the SDK rebuilds only the source's own
 * elements around the translated runs — while the app's own lang-file text prints as Laravel
 * prints it. FRM-2: none of it registers anything at runtime.
 */
class FallbackAndEscapingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.api_url', self::UNREACHABLE_API);
        $app['config']->set('app.fallback_locale', 'en');
        $app->useLangPath(__DIR__ . '/../Fixtures/lang-fallback');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(Client::class);

        // Blade caches a compiled view by its content: a view compiled before this test's
        // directives would be served again, and could not show how `@lang` compiles now.
        foreach (glob(config('view.compiled') . '/*.php') ?: [] as $compiled) {
            @unlink($compiled);
        }

        $this->seedCatalog('en', ['__uncategorized__' => []]);
        $this->seedCatalog('es-es', ['__uncategorized__' => [], 'messages' => []]);
        $this->app->setLocale('es-ES');
    }

    private function _queued(): array
    {
        return array_values($this->app->make(Client::class)->getPendingPhrases());
    }

    public function testTheCatalogWinsOverTheLangFiles(): void
    {
        $this->seedCatalog('es-es', ['messages' => ['Welcome back, {name}' => 'Hola otra vez, {name}']]);

        $this->assertSame('Hola otra vez, Ana', __('messages.welcome', ['name' => 'Ana']));
    }

    public function testALangFileTranslationAnswersWhatTheCatalogLacks(): void
    {
        $this->assertSame('Bienvenido de nuevo, Ana', __('messages.welcome', ['name' => 'Ana']));
        $this->assertSame('Hola Ana', __('Hello :name', ['name' => 'Ana']), 'A JSON line, keyed by the sentence as written.');
        $this->assertSame('3 manzanas', trans_choice('messages.apples', 3), "A plural line, read as Laravel's plural.");
    }

    public function testWithNeitherTheSourceIsReturnedFilled(): void
    {
        $this->assertSame('Goodbye', __('messages.farewell'));
        $this->assertSame('Pay Ana now', __('Pay :name now', ['name' => 'Ana']));
    }

    /** FRM-2: serving a request registers nothing; only `langsys:sync` does. */
    public function testNothingRegistersAtRuntime(): void
    {
        __('messages.welcome', ['name' => 'Ana']);
        __('A sentence nobody has seen');
        trans_choice('messages.apples', 2);

        $this->assertSame([], $this->_queued());
    }

    public function testCatalogMarkupIsEscapedThroughLangAndT(): void
    {
        $this->seedCatalog('es-es', ['__uncategorized__' => ['Pay now' => '<script>alert(1)</script>Pagar']]);

        foreach (["@lang('Pay now')", "@t('Pay now')"] as $directive) {
            $html = Blade::render($directive);

            $this->assertStringNotContainsString('<script>', $html, $directive);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Pagar', $html, $directive);
        }
    }

    public function testTheAppsOwnLangFileMarkupPrintsAsLaravelPrintsIt(): void
    {
        $this->assertSame('Lee los <a href="/terminos">términos</a>', Blade::render("@lang('Terms')"));
    }

    /** A rich source line: the source's own `<a href>` is rebuilt around the translated run. */
    public function testARichSourceLineIsRebuiltFromTheSourcesElements(): void
    {
        $this->seedCatalog('es-es', ['__uncategorized__' => ['Read our {m0o}terms{m0c} today' => 'Lee hoy nuestros {m0o}términos{m0c}']]);

        $this->assertSame('Lee hoy nuestros <a href="/terms">términos</a>', Blade::render('@lang(\'Read our <a href="/terms">terms</a> today\')'));
    }

    /** A translation can place the source's tags but never add one, change an href or add a token. */
    public function testATranslatorAddedTagIsEscapedAndAnUnknownTokenDropped(): void
    {
        $this->seedCatalog('es-es', ['__uncategorized__' => [
            'Read our {m0o}terms{m0c} today' => 'Lee <b>hoy</b> nuestros {m0o}términos{m0c}{m7o}x{m7c}',
        ]]);

        $html = Blade::render('@t(\'Read our <a href="/terms">terms</a> today\')');

        $this->assertStringContainsString('&lt;b&gt;hoy&lt;/b&gt;', $html);
        $this->assertStringContainsString('<a href="/terms">términos</a>', $html);
        $this->assertStringNotContainsString('m7o', $html);
    }

    public function testTheBlockFormStaysLaravels(): void
    {
        $this->assertSame('Goodbye', trim(Blade::render('@lang messages.farewell @endlang')));
    }

    /** `{!! __() !!}` stays the developer's explicit raw choice. */
    public function testRawEchoIsTheDevelopersChoice(): void
    {
        $this->seedCatalog('es-es', ['__uncategorized__' => ['Pay now' => '<b>Pagar</b>']]);

        $this->assertSame('<b>Pagar</b>', Blade::render('{!! __(\'Pay now\') !!}'));
    }
}
