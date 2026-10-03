<?php

namespace Langsys\Laravel\Tests\Contract;

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
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProject(['wk' => ['type' => 'write']], [
            'phrases' => [['category' => null, 'phrase' => 'Total', 'translations' => ['es-es' => 'Total']]],
        ]);
        $this->useKey('wk');
        SyncCommand::$watchChecks = null;
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
            ->expectsOutputToContain('1 phrases already in the catalog, 2 with translations from your lang files,')
            ->assertExitCode(0);

        $this->assertSame([[null, 'Total']], $this->registeredPhrases(), 'A dry run registers nothing.');
    }

    public function testASecondSyncRegistersNothingNew(): void
    {
        $this->artisan('langsys:sync')->assertExitCode(0);
        $first = $this->registeredPhrases();

        $this->useKey('wk');
        $this->artisan('langsys:sync')->expectsOutputToContain('0 new')->assertExitCode(0);
        $this->assertEqualsCanonicalizing($first, $this->registeredPhrases());
    }

    public function testWatchSyncsAgainWhenAFileChanges(): void
    {
        SyncCommand::$watchChecks = 1;
        $view = self::APP . '/views/checkout.blade.php';
        $original = file_get_contents($view);

        try {
            touch($view, time() + 5);
            $this->artisan('langsys:sync', ['--watch' => true, '--interval' => 1])->expectsOutputToContain('Watching for changes')->assertExitCode(0);
        } finally {
            file_put_contents($view, $original);
        }
    }
}
