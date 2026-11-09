<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Langsys\Laravel\Facades\Langsys;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\SDK\Client;

/**
 * MOVE-1 and MOVE-4 at runtime. In Laravel a key group is its lines' category, so moving lines
 * from lang/en/header.php to lang/en/checkout.php moves them to `checkout`, and
 * `langsys.previous_categories` says where they came from. The core does the rest: what renders
 * until the move lands, and what registers. These pin only that the binding hands the move over,
 * and hands it over for the right group, on every path a key is printed through.
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

    /**
     * `''` names the lines of the JSON lang file; a sentence no lang file holds is a literal and
     * is never moved, which is what the config says.
     */
    public function testTheEmptyGroupCoversJsonLinesAndNotLiterals(): void
    {
        config()->set('langsys.previous_categories', ['' => 'header']);
        $this->catalog(['header' => ['Welcome' => 'Bienvenido', 'Goodbye' => 'Adiós']]);

        $this->assertSame('Bienvenido', __('Welcome'));
        $this->assertSame('Goodbye', __('Goodbye'));
    }

    /**
     * MOVE-3: a line registered under its new group without its move is in the catalog there and
     * never registers again, so its translations are never copied. Until the core's plan can
     * carry a move for the lines it registers itself, a sync with one declared registers nothing.
     */
    public function testSyncRegistersNothingWhileAMoveIsDeclared(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/lang-moved']);
        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->app->instance(Client::class, $this->fakeClient);

        $this->artisan('langsys:sync')
            ->expectsOutputToContain('langsys:sync cannot send langsys.previous_categories yet')
            ->assertExitCode(1);
        $this->assertSame([], $this->fakeClient->asked);
    }

    /** The control: with no move declared, and on a dry run with one, the sync goes on to plan. */
    public function testSyncPlansWithoutAMoveAndOnADryRun(): void
    {
        config()->set('langsys.sync_paths', [__DIR__ . '/Fixtures/lang-moved']);
        $this->app->instance(Client::class, $this->fakeClient);

        $this->artisan('langsys:sync')->doesntExpectOutputToContain('cannot send langsys.previous_categories');

        config()->set('langsys.previous_categories', ['checkout' => 'header']);
        $this->artisan('langsys:sync', ['--dry-run' => true])->doesntExpectOutputToContain('cannot send langsys.previous_categories');
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
