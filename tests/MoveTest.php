<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Langsys\Laravel\Facades\Langsys;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\Laravel\Translation\MigrationFiles;
use Langsys\SDK\Client;
use Langsys\SDK\Sync\Planner;
use Langsys\SDK\Sync\SyncPlan;

/**
 * MOVE-1 and MOVE-4 at runtime. In Laravel a key group is its lines' category, so moving lines
 * from lang/en/header.php to lang/en/checkout.php moves them to `checkout`, and
 * `langsys.previous_categories` says where they came from. The core applies that map, and
 * `langsys.messages.previous_category`, both when it renders and when it plans a sync. These pin
 * that the binding hands them over, on every path a line is printed or registered through.
 *
 * The catalog seeded here is what the core loads; `checkout` is empty in it, as it is on the first
 * deploy after the move, before the API has copied `header`'s translations over.
 */
class MoveTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->useLangPath(__DIR__ . '/Fixtures/lang-moved');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The Client the provider builds, so keys resolve through the fixture lang files.
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);
        $this->app->setLocale('es-ES');
    }

    private function catalog(array $catalog): void
    {
        $this->seedCatalog('es-es', $catalog + ['checkout' => []]);
    }

    public function testAKeyInAMovedGroupRendersItsPreviousCategorysTranslation(): void
    {
        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->catalog(['header' => ['Buy now' => 'Comprá ya']]);

        $this->assertSame('Comprá ya', __('checkout.buy'));
    }

    /** The control for the test above: the same catalog with no move declared shows the source. */
    public function testWithoutAMoveTheSameKeyRendersTheSource(): void
    {
        $this->catalog(['header' => ['Buy now' => 'Comprá ya']]);

        $this->assertSame('Buy now', __('checkout.buy'));
    }

    /** A move is declared per group: one naming another group leaves this one alone. */
    public function testAMoveDeclaredForAnotherGroupDoesNotApply(): void
    {
        config()->set('langsys.previous_categories', ['billing' => 'header']);
        $this->catalog(['header' => ['Buy now' => 'Comprá ya']]);

        $this->assertSame('Buy now', __('checkout.buy'));
    }

    /** Once the catalog has the line under its own group, that is what renders (MOVE-4). */
    public function testTheCurrentCategoryOutranksThePreviousOne(): void
    {
        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->catalog(['checkout' => ['Buy now' => 'Compra ahora'], 'header' => ['Buy now' => 'Comprá ya']]);

        $this->assertSame('Compra ahora', __('checkout.buy'));
    }

    /** `''` is a value: the lines had no category before, and the no-category entries are read. */
    public function testAnEmptyPreviousCategoryReadsTheNoCategoryEntries(): void
    {
        config()->set('langsys.previous_categories', ['checkout' => '']);
        $this->catalog(['__uncategorized__' => ['Buy now' => 'Comprá ya']]);

        $this->assertSame('Comprá ya', __('checkout.buy'));
    }

    /** `@lang` prints a line with markup through the rich path (FRM-8), which takes the move too. */
    public function testALineWithMarkupTakesTheMoveToo(): void
    {
        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->catalog(['header' => ['Read our {m0o}terms{m0c}' => 'Lee nuestros {m0o}términos{m0c}']]);

        $this->assertSame('Lee nuestros <a href="/terms">términos</a>', Blade::render("@lang('checkout.terms')"));
    }

    /** A choice resolves through the same key path, and takes the move too. */
    public function testAChoiceTakesTheMove(): void
    {
        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->catalog(['header' => ['{count, plural, one {# apple} other {# apples}}' => '{count, plural, one {# manzana} other {# manzanas}}']]);

        $this->assertSame('3 manzanas', trans_choice('checkout.apples', 3));
    }

    /** `''` is no category: the JSON lang file's lines and a bare sentence alike (MOVE-1). */
    public function testTheEmptyGroupCoversJsonLinesAndLiterals(): void
    {
        config()->set('langsys.previous_categories', ['' => 'header']);
        $this->catalog(['header' => ['Welcome' => 'Bienvenido', 'Goodbye' => 'Adiós']]);

        $this->assertSame('Bienvenido', __('Welcome'));
        $this->assertSame('Adiós', __('Goodbye'));
    }

    /** A facade call naming a moved category takes the map: one item stands for every use. */
    public function testAFacadeCallNamingAMovedCategoryTakesTheMap(): void
    {
        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->catalog(['header' => ['Buy now' => 'Comprá ya']]);

        $this->assertSame('Comprá ya', Langsys::translate('Buy now', 'checkout'));
        $this->assertSame('Buy now', Langsys::translate('Buy now', 'billing'));
    }

    /**
     * MSG-6: with the messages category renamed, a template the catalog has only under the old
     * one renders that translation. The core does it; this pins that the setting reaches it.
     */
    public function testAServerMessageRendersFromThePreviousMessagesCategory(): void
    {
        config()->set('langsys.messages.category', 'Messages');
        config()->set('langsys.messages.previous_category', 'Errors');
        $this->seedCatalog('es-es', ['Messages' => [], 'Errors' => ['The {attribute} field is required.' => 'El campo {attribute} es obligatorio.']]);

        $entry = ['template' => 'The {attribute} field is required.', 'params' => ['attribute' => 'name'], 'message' => 'The name field is required.'];

        $this->assertSame('El campo name es obligatorio.', $this->app->make(Client::class)->translateMessage($entry, 'es-ES'));

        // `''` is a value: the templates had no category before, so the uncategorised entry is read.
        config()->set('langsys.messages.previous_category', '');
        $this->seedCatalog('es-es', ['Messages' => [], '__uncategorized__' => ['The {attribute} field is required.' => 'Sin categoría {attribute}.']]);
        $this->app->forgetInstance(Client::class);

        $this->assertSame('Sin categoría name.', $this->app->make(Client::class)->translateMessage($entry, 'es-ES'));

        // The control: no setting is no rename, and the entry falls back to its message.
        config()->set('langsys.messages.previous_category', null);
        $this->app->forgetInstance(Client::class);

        $this->assertSame('The name field is required.', $this->app->make(Client::class)->translateMessage($entry, 'es-ES'));
    }

    /** An empty env var is an unset one, not "had no category" (MSG-6). */
    public function testAnEmptyMessagesPreviousCategoryEnvVarIsUnset(): void
    {
        $config = static fn () => (require __DIR__ . '/../config/langsys.php')['messages']['previous_category'];

        putenv('LANGSYS_MESSAGES_PREVIOUS_CATEGORY=');
        $_ENV['LANGSYS_MESSAGES_PREVIOUS_CATEGORY'] = $_SERVER['LANGSYS_MESSAGES_PREVIOUS_CATEGORY'] = '';

        try {
            $this->assertNull($config());

            putenv('LANGSYS_MESSAGES_PREVIOUS_CATEGORY=Errors');
            $_ENV['LANGSYS_MESSAGES_PREVIOUS_CATEGORY'] = $_SERVER['LANGSYS_MESSAGES_PREVIOUS_CATEGORY'] = 'Errors';
            $this->assertSame('Errors', $config());
        } finally {
            putenv('LANGSYS_MESSAGES_PREVIOUS_CATEGORY');
            unset($_ENV['LANGSYS_MESSAGES_PREVIOUS_CATEGORY'], $_SERVER['LANGSYS_MESSAGES_PREVIOUS_CATEGORY']);
        }
    }

    /**
     * MOVE-1, MOVE-3: a base-language line no call names registers with its group's move, so the
     * API copies the old translations. Asserted on the plan handed to the core to send.
     */
    public function testSyncRegistersAGroupsLinesWithTheirMove(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/lang-moved']);
        $client = $this->syncClient();

        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->artisan('langsys:sync')->assertExitCode(0);
        $this->assertSame('header', $client->sent['Buy now']['previous_category'] ?? null);

        config()->set('langsys.previous_categories', []);
        $this->artisan('langsys:sync')->assertExitCode(0);
        $this->assertArrayNotHasKey('previous_category', $client->sent['Buy now']);
    }

    /** A dry run with no key plans with no Client, so the map reaches the planner from the config. */
    public function testAKeylessDryRunPlansTheMove(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/lang-moved']);
        config()->set('langsys.api_key', null);
        $this->app->forgetInstance(Client::class);
        config()->set('langsys.previous_categories', ['checkout' => 'header']);

        $this->artisan('langsys:sync', ['--dry-run' => true, '-v' => true])
            ->expectsOutputToContain('No key')
            ->expectsOutputToContain('moved from `header`')
            ->assertExitCode(0);
    }

    /**
     * A keyless dry run moves what it plans under the messages category too (MSG-6), as a real
     * sync does: here a key group shares the category's name. Needs langsys-php 2.2.1's offline
     * options.
     */
    public function testAKeylessDryRunPlansTheMessagesCategorysMove(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/lang-moved']);
        config()->set('langsys.api_key', null);
        $this->app->forgetInstance(Client::class);
        config()->set('langsys.messages.category', 'checkout');
        config()->set('langsys.messages.previous_category', 'Errors');

        $this->artisan('langsys:sync', ['--dry-run' => true, '-v' => true])
            ->expectsOutputToContain('moved from `Errors`')
            ->assertExitCode(0);
    }

    /** A use a move leaves behind is advice: it never fails `--strict`. */
    public function testALeftBehindUseIsAdviceEvenUnderStrict(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/lang-moved']);
        $this->syncClient(new SyncPlan([], [], [], [], [], [], [
            ['phrase' => 'Buy now', 'category' => 'checkout', 'previous_category' => 'header', 'origins' => ['lang/en/checkout.php'], 'left_behind' => ['app/Nav.php:12']],
        ]));

        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])
            ->expectsOutputToContain('which app/Nav.php:12 still registers it under')
            ->assertExitCode(0);
    }

    /** One item can only have moved from one place: a conflict is reported, and fails `--strict`. */
    public function testSyncReportsAMoveConflictAndFailsStrict(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/lang-moved']);
        $this->syncClient(new SyncPlan([], [], [], [], [], [], [], [
            ['phrase' => 'Buy now', 'category' => 'checkout', 'previous_categories' => ['header', 'nav'], 'origins' => ['app/A.php:3 => header', 'app/B.php:9 => nav']],
        ]));

        $this->artisan('langsys:sync', ['--dry-run' => true])
            ->expectsOutputToContain('app/A.php:3 => header; app/B.php:9 => nav: the first is sent')
            ->assertExitCode(0);

        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])->assertExitCode(1);
    }

    /**
     * A FakeClient that plans with the core's planner, or returns $plan, and keeps what a sync would send by
     * phrase instead of sending it.
     */
    private function syncClient(?SyncPlan $plan = null): FakeClient
    {
        $client = new class extends FakeClient {
            public ?SyncPlan $plan = null;
            public array $sent = [];

            public function getProject()
            {
                return ['target_locales' => []];
            }

            // The core's own planner over the fixture's base-language files, with the options the
            // command hands it: this fake has no catalog or files of its own to plan against.
            public function planSync(array $hits, array $targets = [], array $options = [])
            {
                return $this->plan ?? Planner::offline($hits, MigrationFiles::for(app('translation.loader'), app()->langPath(), 'en'), [], $options);
            }

            public function applySync(SyncPlan $plan)
            {
                $this->sent = array_column($plan->toRegister(), null, 'phrase');

                return ['success' => true, 'registered' => count($this->sent), 'translations' => 0];
            }
        };
        $client->plan = $plan;
        $this->app->instance(Client::class, $client);

        return $client;
    }

    /**
     * The facade hands the core's moved form over as given. Asserted on what the SDK received: a
     * wrapper that flattened it to the category alone would still render a translation here.
     */
    public function testTheFacadeHandsTheMovedFormToTheSdkUnchanged(): void
    {
        $client = new class extends FakeClient {
            public array $categories = [];

            public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null, array $params = [])
            {
                $this->categories[] = $category;

                return parent::translate($phrase, $locale, $category, $contentBlockId, $params);
            }

            public function resolve($phrase, $locale = null, $category = '__uncategorized__', array $params = [])
            {
                $this->categories[] = $category;

                return parent::resolve($phrase, $locale, $category, $params);
            }
        };

        $this->app->instance(Client::class, $client);
        $this->app->forgetInstance(LangsysTranslator::class);
        Langsys::clearResolvedInstances();
        $client->seed('es-ES', 'Checkout', ['Buy now' => 'Compra ahora']);

        $moved = ['category' => 'Checkout', 'previous_category' => 'Header'];

        $this->assertSame('Compra ahora', Langsys::translate('Buy now', $moved, [], 'es-ES'));
        Langsys::resolve('Buy now', $moved, [], 'es-ES');

        $this->assertSame([$moved, $moved], $client->categories);
    }
}
