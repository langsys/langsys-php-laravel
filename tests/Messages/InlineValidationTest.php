<?php

namespace Langsys\Laravel\Tests\Messages;

use Langsys\Laravel\Messages\FormRequestSource;
use Langsys\Laravel\Tests\Fixtures\Http\SubscribeRequest;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Messages\MessageCatalogCommand;

/**
 * MSG-7: the listing reads the rules an app declares where Laravel declares them — a FormRequest a
 * route takes, a closure's included — and reports each call that validates inline, which declares
 * its rules where no listing can read them, with its file, its line and what would list it.
 */
class InlineValidationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/InlineValidation';

    protected function getPackageProviders($app): array
    {
        return [\Livewire\LivewireServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.sync_paths', [realpath(self::FIXTURES)]);
    }

    protected function defineRoutes($router): void
    {
        $router->post('/topics', fn (SubscribeRequest $request) => 'subscribed');
    }

    public function testAFormRequestAClosureRouteTakesIsListed(): void
    {
        $catalog = MessageCatalogCommand::collect([FormRequestSource::fromRoutes($this->app['router'])]);

        $this->assertContains('The topic field is required.', array_column($catalog->templates(), 'template'));
    }

    /** `php artisan route:cache` keeps a closure serialized; it is read as Laravel reads it to run the route. */
    public function testACachedClosureRoutesFormRequestIsListed(): void
    {
        $route = $this->app['router']->getRoutes()->match(\Illuminate\Http\Request::create('/topics', 'POST'));
        $route->prepareForSerialization();
        $this->assertIsString($route->getAction('uses'), 'Control: the closure is serialized, as route:cache leaves it.');

        $catalog = MessageCatalogCommand::collect([FormRequestSource::fromRoutes($this->app['router'])]);

        $this->assertContains('The topic field is required.', array_column($catalog->templates(), 'template'));
    }

    public function testEachInlineValidationIsReportedWithItsFileAndLine(): void
    {
        $problems = MessageCatalogCommand::collect([FormRequestSource::fromRoutes($this->app['router'])])->problems();
        $at = fn (string $file, int $line) => realpath(self::FIXTURES) . "/$file:$line";
        $fix = 'declare these rules in a FormRequest a route\'s action takes';

        $this->assertSame([
            $at('AccountController.php', 15) . ": validates inline with ->validate(), so the messages it can send are not listed — $fix",
            $at('OrderController.php', 13) . ": validates inline with ->validate(), so the messages it can send are not listed — $fix",
            $at('OrderController.php', 14) . ": validates inline with Validator::make(), so the messages it can send are not listed — $fix",
            $at('OrderController.php', 17) . ": validates inline with Validator::validate(), so the messages it can send are not listed — $fix",
            $at('OrderController.php', 18) . ": validates inline with validator(), so the messages it can send are not listed — $fix",
            $at('OrderController.php', 19) . ": validates inline with ->validateWithBag(), so the messages it can send are not listed — $fix",
            $at('OrderController.php', 26) . ": validates inline with ->validate(), so the messages it can send are not listed — $fix",
            $at('routes.php', 7) . ": validates inline with ->validate(), so the messages it can send are not listed — $fix",
        ], $problems);
    }

    /** A file inside the app is named from the app's root, as `langsys:sync` names the files it reads. */
    public function testAFileInTheAppIsNamedFromItsRoot(): void
    {
        $this->app->setBasePath(dirname(realpath(self::FIXTURES)));

        $problems = MessageCatalogCommand::collect([FormRequestSource::fromRoutes($this->app['router'])])->problems();

        $this->assertStringStartsWith('InlineValidation/AccountController.php:15: validates inline', $problems[0]);
    }

    /** `--strict` fails on a message the listing reports, as on anything else it could not register. */
    public function testStrictFailsOnAnInlineValidation(): void
    {
        // Offline, as a CI job with no secrets checks: the plan compares nothing with a catalog.
        config()->set('langsys.api_key', null);
        $this->app->forgetInstance(\Langsys\SDK\Client::class);

        $this->artisan('langsys:sync', ['--dry-run' => true])->assertExitCode(0);
        $this->artisan('langsys:sync', ['--dry-run' => true, '--strict' => true])
            ->expectsOutputToContain('OrderController.php:13: validates inline')
            ->expectsOutputToContain('AccountController.php:15: validates inline')
            ->assertExitCode(1);
        $this->artisan('langsys:messages', ['--strict' => true])->assertExitCode(1);
    }
}
