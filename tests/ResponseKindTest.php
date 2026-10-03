<?php

namespace Langsys\Laravel\Tests;

use Langsys\Laravel\Tests\Fixtures\Http\InertiaMiddleware;
use Langsys\Laravel\Tests\Fixtures\Mail\ShippingController;
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
        $app['config']->set('mail.default', 'array');
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views', ...(array) $app['config']->get('view.paths')]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The Client the provider builds, so keys resolve through the fixture lang files.
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);
        $this->seedCatalog('es-es', ['__uncategorized__' => ['Pay now' => 'Pagar ahora', 'Your order has shipped' => 'Tu pedido ha salido', 'Thanks for your order' => 'Gracias por tu pedido'], 'messages' => ['Welcome back, {name}' => 'Hola de nuevo, {name}']]);
        $this->seedCatalog('en', ['__uncategorized__' => []]);
        $this->app->setLocale('es-ES');
        RecordingChannel::$sent = [];
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => __('Pay now'));
        $router->middleware(['web', InertiaMiddleware::class])->get('/spa', fn () => __('Pay now'));
        $router->middleware(['web', InertiaMiddleware::class])->get('/spa/ship', [ShippingController::class, 'ship']);
        $router->middleware(['web', InertiaMiddleware::class])->get('/spa/preview', [ShippingController::class, 'preview']);
        $router->middleware(['web', InertiaMiddleware::class])->get('/spa/plain-mail', function () {
            \Illuminate\Support\Facades\Mail::send('emails.shipped', [], fn ($message) => $message->to('ana@example.com')->subject('Shipped'));

            return __('Pay now');
        });
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

    /**
     * A Mailable sent while serving an Inertia page is read by its recipient, not by the page: its
     * envelope subject and its Blade body are translated, though the page itself gets the source.
     * Sent the way an app sends one — controller, service, the Mail facade — so the check runs at
     * the depth a real app reaches.
     */
    public function testAMailableSentFromAClientPageIsTranslated(): void
    {
        $this->get('/spa/ship')->assertSeeText('Pay now');

        $sent = $this->app['mailer']->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('Tu pedido ha salido', $sent[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('Gracias por tu pedido', $sent[0]->getOriginalMessage()->getHtmlBody());
    }

    /** Mail sent from a view with no Mailable is read by its recipient too. */
    public function testPlainMailSentFromAClientPageIsTranslated(): void
    {
        $this->get('/spa/plain-mail')->assertSeeText('Pay now');

        $this->assertStringContainsString('Gracias por tu pedido', $this->app['mailer']->getSymfonyTransport()->messages()[0]->getOriginalMessage()->getHtmlBody());
    }

    /** A Mailable rendered as a preview from an Inertia route is translated as it would be sent. */
    public function testAMailablePreviewFromAClientPageIsTranslated(): void
    {
        $this->get('/spa/preview')->assertSee('Gracias por tu pedido', false);
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
