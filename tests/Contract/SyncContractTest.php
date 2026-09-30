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
        $app['config']->set('app.fallback_locale', 'en');
        $app['config']->set('langsys.sync_paths', [self::APP . '/app', self::APP . '/views']);
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
        ] as $phrase) {
            $this->assertContains($phrase, $registered);
        }

        $this->assertSame(1, count(array_filter($registered, fn ($p) => $p[1] === 'Total')), 'Already in the catalog: not registered again.');
    }

    public function testANonLiteralCallIsReportedWithItsFileAndLineAndFailsStrict(): void
    {
        $this->artisan('langsys:sync', ['--dry-run' => true])
            ->expectsOutputToContain('OrderController.php:12: __() is called with something that is not a literal')
            ->expectsOutputToContain('checkout.blade.php:6: __() is called with something that is not a literal')
            ->assertExitCode(0);

        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])->assertExitCode(1);
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
