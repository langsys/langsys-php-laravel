<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Support\ValueSetDiscovery;
use Langsys\Laravel\Tests\Fixtures\app\Enums\OrderStatus;
use Langsys\Laravel\Tests\Fixtures\app\Enums\Plan;
use Langsys\Laravel\Tests\Fixtures\app\Models\Category;
use Langsys\Laravel\Tests\Fixtures\elsewhere\Region;
use Langsys\SDK\Client;

/**
 * FRM-7: a finite set of translatable values is declared — a backed enum marked `#[TranslatesAs]`,
 * or any class implementing the core's `TranslatableValues` — and found in `app/` without
 * configuration, as Laravel finds its listeners; `langsys.value_sets` adds classes kept
 * elsewhere. A declared value is written into its sentence, which is the phrase, so the sentence
 * is translated whole; an undeclared value stays a placeholder.
 */
class ValueSetsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->useAppPath(__DIR__ . '/Fixtures/app');
        $app['config']->set('langsys.value_sets', [Region::class]);
        $app['config']->set('langsys.api_url', self::UNREACHABLE_API);
    }

    protected function setUp(): void
    {
        parent::setUp();

        ValueSetDiscovery::clear();
        Category::$rows = ['Books', 'Music'];
        $this->app->forgetInstance(Client::class);
        $this->seedCatalog('es-es', ['__uncategorized__' => [
            'The order is Shipped.'  => 'El pedido está enviado.',
            'Browse Books'           => 'Explorar libros',
        ]]);
        $this->app->setLocale('es-ES');
    }

    protected function tearDown(): void
    {
        ValueSetDiscovery::clear();
        parent::tearDown();
    }

    public function testDeclarationsAreFoundWithoutConfigurationAndAddedFromConfig(): void
    {
        $this->assertSame([OrderStatus::class, Plan::class, Category::class, Region::class], ValueSetDiscovery::classes());
    }

    /** The case, its backing value and its display word all name the same member. */
    public function testADeclaredValueLooksUpTheSentenceWithTheWordWrittenIn(): void
    {
        $this->assertSame('El pedido está enviado.', __('The order is :status.', ['status' => OrderStatus::Shipped]));
        $this->assertSame('El pedido está enviado.', __('The order is :status.', ['status' => 'shipped']));
        $this->assertSame('Explorar libros', __('Browse :category', ['category' => 'Books']));
    }

    /** An enum with no `label()` shows its value as the word, which is why the docs recommend one. */
    public function testWithoutALabelTheValueIsTheWord(): void
    {
        $this->assertSame('Upgrade to Pro', __('Upgrade to :plan', ['plan' => Plan::Pro]));
    }

    /** A value in no declared set for its placeholder is user data: a placeholder, and nothing registers. */
    public function testAnUndeclaredValueStaysAPlaceholder(): void
    {
        $this->assertSame('The order is Ana.', __('The order is :status.', ['status' => 'Ana']));
        $this->assertSame([], $this->app->make(Client::class)->getPendingPhrases());
    }

    /** The one runtime registration: a declared value added since the last sync, its sentence missing from the catalog. */
    public function testAValueAddedSinceTheLastSyncRegistersItsSentence(): void
    {
        Category::$rows[] = 'Games';

        $this->assertSame('Browse Games', __('Browse :category', ['category' => 'Games']));
        $this->assertContains('Browse Games', array_column($this->app->make(Client::class)->getPendingPhrases(), 'phrase'));
    }

    /** `langsys:cache` writes what discovery found; a cached list is read instead of scanning. */
    public function testTheCacheIsWrittenAndRead(): void
    {
        $this->artisan('langsys:cache')->assertExitCode(0);
        $this->assertFileExists(ValueSetDiscovery::cachePath());

        file_put_contents(ValueSetDiscovery::cachePath(), '<?php return ' . var_export([Plan::class], true) . ';');
        $this->assertSame([Plan::class, Region::class], ValueSetDiscovery::classes());

        $this->artisan('langsys:clear')->assertExitCode(0);
        $this->assertFileDoesNotExist(ValueSetDiscovery::cachePath());
    }
}
