<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Tests\Fixtures\Http\InertiaMiddleware;
use Langsys\Laravel\Tests\Fixtures\Notifications\Recipient;
use Langsys\Laravel\Tests\Fixtures\Notifications\RecordingChannel;
use Langsys\Laravel\Tests\Fixtures\Notifications\WelcomeNotification;
use Langsys\SDK\Client;

/**
 * FRM-4: what `__()` returns depends on what the response is, decided from Laravel's own
 * structure and overridable per route group. A page the server renders gets the translation; an
 * Inertia page gets the source, because its own browser SDK translates it; mail and notifications
 * always get the translation, in the recipient's language, whatever the request that sent them.
 */
class ResponseKindTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Inertia\ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->useLangPath(__DIR__ . '/Fixtures/lang');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The Client the provider builds, so keys resolve through the fixture lang files.
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);
        $this->seedCatalog('es-es', ['__uncategorized__' => ['Pay now' => 'Pagar ahora'], 'messages' => ['Welcome back, {name}' => 'Hola de nuevo, {name}']]);
        $this->seedCatalog('en', ['__uncategorized__' => []]);
        $this->app->setLocale('es-ES');
        RecordingChannel::$sent = [];
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => __('Pay now'));
        $router->middleware(['web', InertiaMiddleware::class])->get('/spa', fn () => __('Pay now'));
        $router->middleware(['web', InertiaMiddleware::class])->get('/spa/notify', function () {
            (new Recipient('Ana', 'es-ES'))->notifyNow(new WelcomeNotification());

            return __('Pay now');
        });
    }

    public function testAPageTheServerRendersGetsTheTranslation(): void
    {
        $this->get('/page')->assertSeeText('Pagar ahora');
    }

    public function testAnInertiaPageGetsTheSource(): void
    {
        $this->get('/spa')->assertSeeText('Pay now');
    }

    /** An Inertia visit announces itself; a route without Inertia's middleware still answers it as a client page. */
    public function testAnInertiaVisitGetsTheSource(): void
    {
        $this->get('/page', ['X-Inertia' => 'true'])->assertSeeText('Pay now');
    }

    public function testTheRouteGroupOverrideWins(): void
    {
        config()->set('langsys.response_kinds', ['web' => 'client']);
        $this->get('/page')->assertSeeText('Pay now');

        config()->set('langsys.response_kinds', ['web' => 'server']);
        $this->get('/spa')->assertSeeText('Pagar ahora');
    }

    /** A notification sent while serving an Inertia page is read in the recipient's language all the same. */
    public function testANotificationSentFromAClientPageIsTranslated(): void
    {
        $this->get('/spa/notify')->assertSeeText('Pay now');

        $this->assertSame(['Hola de nuevo, Ana'], RecordingChannel::$sent);
    }

    /** Outside a request — a queued job, a command — there is no page to hand the source to. */
    public function testWithNoRequestTheTranslationIsReturned(): void
    {
        $this->assertSame('Pagar ahora', __('Pay now'));
    }
}
