<?php

namespace Langsys\Laravel\Tests\Contract;

use Illuminate\Support\Facades\Log;
use Langsys\Laravel\Console\SyncCommand;

/**
 * FRM-2 against the contract fixture: `php artisan langsys:sync` registers every literal call in
 * the app's PHP and Blade, and every base-language line, and nothing it cannot read as a literal.
 * What the server accepted is read back.
 */
class SyncContractTest extends ContractTestCase
{
    private const APP = __DIR__ . '/../Fixtures/sync';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->useLangPath(self::APP . '/lang');
        $app->useAppPath(self::APP . '/app');
        $app['config']->set('app.fallback_locale', 'en');
        $app['config']->set('langsys.sync_paths', [self::APP . '/app', self::APP . '/views']);
    }

    protected function defineRoutes($router): void
    {
        $router->post('/handles', [\Langsys\Laravel\Tests\Fixtures\sync\app\HandleController::class, 'store']);
        $router->middleware(['web', 'langsys.locale'])->get('/checkout', fn () => view()->file(self::APP . '/views/checkout.blade.php', ['label' => 'Total', 'count' => 3]));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProject(['wk' => ['type' => 'write']], [
            'phrases' => [['category' => null, 'phrase' => 'Total', 'translations' => ['es-es' => 'Total']]],
        ]);
        $this->useKey('wk');
        SyncCommand::$watching = null;
    }

    public function testEveryLiteralCallAndBaseLanguageLineIsRegistered(): void
    {
        $this->artisan('langsys:sync')->assertExitCode(0);

        $registered = $this->registeredPhrases();

        foreach ([
            ['messages', 'Welcome back, {name}'],
            [null, 'Pay {amount} now'],
            [null, '{count, plural, one {# item} other {# items}}'],
            [null, 'Place order'],
            [null, 'Ship to {city}'],
        ] as $phrase) {
            $this->assertContains($phrase, $registered);
        }

        $this->assertSame(1, count(array_filter($registered, fn ($p) => $p[1] === 'Total')), 'Already in the catalog: not registered again.');

        // MIG-3: a key call registers its line, never the key.
        $this->assertSame([], array_values(array_filter($registered, fn ($p) => $p[1] === 'messages.welcome')), 'The key is never a phrase.');

        // MSG-7: the validation listing registers its templates under the messages category.
        $this->assertContains(['Errors', 'Use lowercase letters and dashes only.'], $registered);
    }

    /**
     * FRM-2: a literal holding `:attribute` is a phrase no request looks up. It registers nothing on
     * its own, is reported as registered through the validation listing, and fails nothing.
     */
    public function testALineHoldingALabelPlaceholderRegistersOnlyThroughTheValidationListing(): void
    {
        $this->artisan('langsys:sync')
            ->expectsOutputToContain('OrderController.php:14: "The :attribute is not a code we issued." holds :attribute, so it is registered through the validation listing')
            ->assertExitCode(0);

        $registered = array_column($this->registeredPhrases(), 1);
        $this->assertContains('Place order', $registered, 'Control: the sync registered.');
        $this->assertSame([], array_values(array_filter($registered, fn (string $phrase) => str_contains($phrase, 'not a code we issued'))));
    }

    /**
     * FRM-2: a key built at runtime inside a literal group names one of that group's lines, all of
     * which register, so it is listed as covered and fails nothing — `validation` too, the
     * validator's group, whose sentences register per field; a key with no literal group is still
     * reported.
     */
    public function testARuntimeKeyInsideALiteralGroupIsCoveredByTheGroup(): void
    {
        $this->artisan('langsys:sync', ['--dry-run' => true])
            ->expectsOutputToContain('OrderController.php:15: __() builds its key at runtime, inside the messages group, whose every line is registered')
            ->expectsOutputToContain('OrderController.php:16: __() builds its key at runtime, inside the validation group, whose every line is registered')
            ->doesntExpectOutputToContain('OrderController.php:15: __() is called with something that is not a literal')
            ->doesntExpectOutputToContain('OrderController.php:16: __() is called with something that is not a literal')
            ->expectsOutputToContain('OrderController.php:12: __() is called with something that is not a literal')
            ->assertExitCode(0);
    }

    /**
     * MSG-7: a call inside an app message's template() is that message's listing even when it
     * reads no literal and the contract is inherited — so it is not reported, and `--strict` holds.
     */
    public function testANonLiteralCallInAnInheritedAppMessageIsItsListing(): void
    {
        $this->artisan('langsys:sync', ['--dry-run' => true])
            ->expectsOutputToContain("PlanExpired.php:12: __() states PlanExpired's message, registered through the message listing")
            ->doesntExpectOutputToContain('PlanExpired.php:12: __() is called with something that is not a literal')
            ->assertExitCode(0);
    }

    public function testANonLiteralCallIsReportedWithItsFileAndLineAndFailsStrict(): void
    {
        $this->artisan('langsys:sync', ['--dry-run' => true])
            ->expectsOutputToContain('OrderController.php:12: __() is called with something that is not a literal')
            ->expectsOutputToContain('checkout.blade.php:6: __() is called with something that is not a literal')
            ->assertExitCode(0);

        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])->assertExitCode(1);
    }

    /**
     * MSG-7: a message the app defines itself registers once, under the messages category; the
     * `__()` literal inside its template method is that listing's, never an uncategorised phrase.
     */
    public function testAnAppMessageRegistersUnderTheCategoryAndItsLiteralNowhereElse(): void
    {
        $this->artisan('langsys:sync')
            ->expectsOutputToContain("QuotaExceeded.php:16: __() states QuotaExceeded's message, registered through the message listing")
            ->assertExitCode(0);

        $registered = $this->registeredPhrases();
        $this->assertContains(['Errors', 'You have used all {limit} requests this month.'], $registered);

        // FRM-2 (8.5.8): neither an inherited app message, nor an enum's case, nor a sentence a rule
        // reads from elsewhere registers bare beside its listing.
        foreach (['Your plan allows {limit} projects.', 'That record does not exist.', 'Use lowercase letters and dashes only.'] as $sentence) {
            $this->assertContains(['Errors', $sentence], $registered, $sentence);
            $this->assertNotContains([null, $sentence], $registered, $sentence);
        }
        $this->assertContains(['Errors', 'That record does not exist.'], $registered, 'An enum registers each case.');
        $this->assertNotContains([null, 'You have used all {limit} requests this month.'], $registered);
    }

    /**
     * MIG-9 against the server: a line the other locales' lang files translate registers in one call
     * with those translations, and the server stores them on the phrase — converted as the source
     * is, so the stored Spanish carries `{name}`. A phrase no lang file translates registers bare.
     */
    public function testALineTheLangFilesTranslateIsStoredWithItsTranslations(): void
    {
        $this->artisan('langsys:sync')->assertExitCode(0);

        $stored = $this->storedTranslations();

        $this->assertSame(['es-es' => 'Bienvenido de nuevo, {name}'], $stored['messages|Welcome back, {name}'] ?? null);
        $this->assertSame(['es-es' => 'Realizar pedido'], $stored['|Place order'] ?? null);
        $this->assertSame([], $stored['|Pay {amount} now'] ?? null, 'Control: a phrase no lang file translates registers bare.');
    }

    /** The plan counts a line the other locales' files translate, which registers with those translations. */
    public function testTheCountsNameWhatTheLangFilesAlreadyTranslate(): void
    {
        $this->artisan('langsys:sync', ['--dry-run' => true])
            ->expectsOutputToContain('1 phrases already in the catalog, 3 with translations from your lang files,')
            ->assertExitCode(0);

        $this->assertSame([[null, 'Total']], $this->registeredPhrases(), 'A dry run registers nothing.');
    }

    /**
     * FRM-8 with FRM-2: a rich `@lang`/`@t` line registers once, in the form the page looks it up
     * by, so the translation of what sync registered is what the page shows. A copy in raw HTML
     * would be registered, paid for in every locale, and never read.
     */
    public function testARichLineRegistersOnceInTheFormThePageLooksUp(): void
    {
        $this->artisan('langsys:sync')->assertExitCode(0);

        $literals = array_filter($this->registeredPhrases(), fn (array $phrase) => $phrase[0] === null);
        $rich = array_values(array_filter(array_column($literals, 1), fn (string $phrase) => str_contains($phrase, 'terms')));
        $this->assertSame(['Read our {m0o}terms{m0c} today'], $rich, 'One phrase, tokenized, and no copy in raw HTML.');

        $this->seedProject(['wk' => ['type' => 'write']], [
            'phrases' => [['category' => null, 'phrase' => $rich[0], 'translations' => ['es-es' => 'Lee hoy nuestros {m0o}términos{m0c}']]],
        ]);
        $this->useKey('wk');

        $this->get('/checkout?locale=es-ES')->assertOk()->assertSee('>Lee hoy nuestros <a href="/terms">términos</a></p>', false);
    }

    /** `@choice` prints raw too (FRM-8): its plural with markup registers once, tokenized in every branch, and the page shows its translation. */
    public function testARichPluralRegistersOnceInTheFormThePageLooksUp(): void
    {
        $this->artisan('langsys:sync')->assertExitCode(0);

        $plural = array_values(array_filter(array_column($this->registeredPhrases(), 1), fn (string $phrase) => str_contains($phrase, 'left')));
        $this->assertSame(['{count, plural, one {# {m0o}item{m0c} left} other {# {m1o}items{m1c} left}}'], $plural, 'One phrase, tokenized in every branch, and no copy in raw HTML.');

        $this->seedProject(['wk' => ['type' => 'write']], [
            'phrases' => [['category' => null, 'phrase' => $plural[0], 'translations' => ['es-es' => '{count, plural, one {Queda # {m0o}artículo{m0c}} other {Quedan # {m1o}artículos{m1c}}}']]],
        ]);
        $this->useKey('wk');

        $this->get('/checkout?locale=es-ES')->assertOk()->assertSee('>Quedan 3 <b>artículos</b></p>', false);
    }

    /**
     * The same for a key: a base-language line with markup, reached through `@lang`, registers once,
     * tokenized under its group, with no raw copy from the lang-file pass. Another locale's line is
     * carried in the same form when its tags line up with the source's, and not at all when they
     * do not. The page shows the carried translation rebuilt from the source's own elements.
     */
    public function testARichKeyLineRegistersTokenizedWithTheTranslationsThatLineUp(): void
    {
        $this->artisan('langsys:sync')->assertExitCode(0);

        $legal = array_values(array_filter($this->registeredPhrases(), fn (array $phrase) => $phrase[0] === 'legal'));
        $this->assertEqualsCanonicalizing([['legal', 'Accept the {m0o}terms{m0c} to continue'], ['legal', 'See our {m0o}policy{m0c}']], $legal);

        $stored = $this->storedTranslations();
        $this->assertSame(['es-es' => 'Acepta los {m0o}términos{m0c} para continuar'], $stored['legal|Accept the {m0o}terms{m0c} to continue'] ?? null);
        $this->assertSame([], $stored['legal|See our {m0o}policy{m0c}'] ?? null, 'Tags that do not line up with the source are not carried.');

        $this->useKey('wk');
        $this->get('/checkout?locale=es-ES')->assertOk()->assertSee('>Acepta los <a href="/terms">términos</a> para continuar</p>', false);
    }

    /**
     * Sync reads a view with the app's own compiler, value marker aside: the marker's warning about
     * a value with no name of its own belongs to rendering, not to a scan. Control: rendering the
     * same view warns.
     */
    public function testScanningAViewRunsWithoutTheValueMarker(): void
    {
        Log::spy();

        $this->artisan('langsys:sync', ['--dry-run' => true])->assertExitCode(0);
        Log::shouldNotHaveReceived('warning');

        foreach (glob(config('view.compiled') . '/*.php') ?: [] as $compiled) {
            @unlink($compiled);
        }

        $this->get('/checkout')->assertOk();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '`strlen($label) + 1`'))->once();
    }

    /**
     * A view using components the app registered itself — an alias, a class namespace, an anonymous
     * path under a prefix, as icon and UI packages register theirs — is read as the app's own
     * compiler reads it.
     */
    public function testAViewUsingTheAppsOwnComponentsIsRead(): void
    {
        config()->set('langsys.sync_paths', [self::APP . '-components/views']);
        // The namespace a real app's composer.json names, which Blade asks before an anonymous
        // component; this app's path is a fixture directory with none.
        (fn () => $this->namespace = 'App\\')->call($this->app);
        \Illuminate\Support\Facades\Blade::component(\Langsys\Laravel\Tests\Fixtures\View\Icon::class, 'icon-user');
        \Illuminate\Support\Facades\Blade::componentNamespace('Langsys\\Laravel\\Tests\\Fixtures\\View', 'fx');
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(self::APP . '-components/ui', 'ui');
        \Illuminate\Support\Facades\View::addLocation(self::APP . '-components');
        \Illuminate\Support\Facades\Blade::anonymousComponentNamespace('ns-ui', 'chips');

        $this->artisan('langsys:sync')->assertExitCode(0);

        $this->assertContains([null, 'Verified member'], $this->registeredPhrases());
        $this->assertContains([null, 'Since 2020'], $this->registeredPhrases());
    }

    /**
     * The block form, `@lang … @endlang` and `@t … @endt`, is a literal call like `@lang(…)`, read
     * where Blade reads a directive: its text registers, its values become markers, and the lines
     * after it keep their place. A block holding an echo, a directive or another block prints text
     * known only as it renders, and is reported with its line. An `@` inside a word or a comment
     * is no directive.
     */
    public function testTheBlockFormRegistersAsTheCallItPrints(): void
    {
        config()->set('langsys.sync_paths', [self::APP . '-blocks/views']);

        $this->artisan('langsys:sync')
            ->expectsOutputToContain('blocks.blade.php:7: Lang::get() is called with something that is not a literal')
            ->expectsOutputToContain('blocks.blade.php:12: Lang::get() is called with something that is not a literal')
            ->expectsOutputToContain('blocks.blade.php:13: Lang::get() is called with something that is not a literal')
            ->assertExitCode(0);

        $registered = $this->registeredPhrases();

        foreach (['Pay now', 'Thanks for shopping', 'Hello {name}, welcome', 'Place order', 'Write to help@example.com', 'Spaced call', '{item}', 'Last block'] as $phrase) {
            $this->assertContains([null, $phrase], $registered, $phrase);
        }

        $this->assertSame([], array_values(array_filter(array_column($registered, 1), fn (string $phrase) => preg_match('/(?<![\w@])@(?:lang|t|if|endif|endlang|endt)\b/', $phrase) === 1)), 'No directive registers as text.');
        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])->assertExitCode(1);
    }

    /**
     * Views are read as the app's own compiler reads them, value marker aside: a call inside the
     * app's own directive or inside a package's tag syntax registers like any other.
     */
    public function testACallInsideTheAppsOwnBladeSyntaxIsRead(): void
    {
        config()->set('langsys.sync_paths', [self::APP . '-syntax/views']);
        \Illuminate\Support\Facades\Blade::directive('money', fn (string $expression) => "<?php echo e($expression); ?>");
        \Illuminate\Support\Facades\Blade::precompiler(fn (string $template) => str_replace(['<pkg:title text="', '" />'], ['{{ ', ' }}'], $template));

        $this->artisan('langsys:sync')->assertExitCode(0);

        $this->assertContains([null, 'Total due'], $this->registeredPhrases());
        $this->assertContains([null, 'Archive this project?'], $this->registeredPhrases());
    }

    /** A view that cannot compile — a component the app no longer has — is reported, and the rest is read; `--strict` fails. */
    public function testAViewThatCannotCompileIsReportedAndTheRestRead(): void
    {
        config()->set('langsys.sync_paths', [self::APP . '-broken/views']);
        (fn () => $this->namespace = 'App\\')->call($this->app);

        $this->artisan('langsys:sync')->expectsOutputToContain('broken.blade.php cannot be read:')->assertExitCode(0);
        $this->assertContains([null, 'Still read'], $this->registeredPhrases());

        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])->assertExitCode(1);
    }

    public function testASecondSyncRegistersNothingNew(): void
    {
        $this->artisan('langsys:sync')->assertExitCode(0);
        $first = $this->registeredPhrases();

        $this->useKey('wk');
        $this->artisan('langsys:sync')->expectsOutputToContain('0 new')->assertExitCode(0);
        $this->assertEqualsCanonicalizing($first, $this->registeredPhrases());
    }

    /**
     * `--watch` waits out its interval between checks, whatever the terminal's input holds, and a
     * view, a base-language file or the app's validation lines changed while it waits are synced at
     * the next check: the view's new phrase, the file's new line and the custom message register.
     */
    public function testWatchSyncsAgainWhenAFileChanges(): void
    {
        $view = self::APP . '/views/checkout.blade.php';
        $lines = self::APP . '/lang/en/messages.php';
        $validation = self::APP . '/lang/en/validation.php';
        $originals = [$view => file_get_contents($view), $lines => file_get_contents($lines)];
        $checked = [];
        \Illuminate\Support\Facades\Route::post('/newsletter', [\Langsys\Laravel\Tests\Fixtures\SyncWatch\NewsletterController::class, 'store']);

        SyncCommand::$watching = function (int $check) use ($view, $lines, $validation, $originals, &$checked) {
            $checked[$check] = microtime(true);

            if ($check === 1) {
                $this->assertNotContains(['messages', 'Watched line'], $this->registeredPhrases(), 'Nothing changed yet: nothing synced again.');
                file_put_contents($lines, str_replace("];", "    'watched' => 'Watched line',\n];", $originals[$lines]));
                file_put_contents($validation, "<?php\n\nreturn ['custom' => ['email' => ['required' => 'Tell us your email.']]];\n");
                touch($lines, time() + 5);
            }

            // The lang files alone changed: the next check syncs them.
            if ($check === 2) {
                $this->assertContains(['messages', 'Watched line'], $this->registeredPhrases(), 'A lang file changed on its own is synced.');
                file_put_contents($view, $originals[$view] . "<p>{{ __('Watched phrase') }}</p>\n");
                touch($view, time() + 10);
            }

            return $check < 3;
        };

        try {
            $this->artisan('langsys:sync', ['--watch' => true, '--interval' => 1])
                ->expectsOutputToContain('Watching for changes. Press Ctrl+C to stop, and run it again after changing a PHP class or a routes file')
                ->assertExitCode(0);
        } finally {
            foreach ($originals as $file => $original) {
                file_put_contents($file, $original);
            }

            @unlink($validation);
        }

        $this->assertContains([null, 'Watched phrase'], $this->registeredPhrases());
        $this->assertContains(['messages', 'Watched line'], $this->registeredPhrases());
        $this->assertContains(['Errors', 'Tell us your email.'], $this->registeredPhrases());
        $this->assertGreaterThan(0.9, $checked[2] - $checked[1], 'The interval was waited out between checks.');
    }
}
