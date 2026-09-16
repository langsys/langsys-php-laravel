<?php

namespace Langsys\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Langsys\Laravel\Cache\LaravelCacheAdapter;
use Langsys\Laravel\Http\Middleware\AttachServerMessages;
use Langsys\Laravel\Http\Middleware\DetectLocale;
use Langsys\Laravel\Http\Middleware\FlushPendingRegistrations;
use Langsys\Laravel\Http\Middleware\TranslateResponse;
use Langsys\Laravel\Messages\MessageValidator;
use Langsys\SDK\Client;

class LangsysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/langsys.php', 'langsys');

        $this->app->singleton(Client::class, function (Application $app) {
            $config = $app['config']['langsys'];

            return new Client($config['api_key'], $config['project_id'], [
                'api_url'           => $config['api_url'],
                'messages_category' => $config['messages']['category'],
                'cache'             => new LaravelCacheAdapter(
                    $app['cache']->store($config['cache']['store']),
                    $config['cache']['prefix'],
                    $config['cache']['ttl'],
                    $config['project_id'],
                ),
            ]);
        });

        $this->app->singleton(LangsysTranslator::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/langsys.php' => config_path('langsys.php'),
        ], 'langsys-config');

        $this->_registerBladeDirective();
        $this->_registerMiddlewareAliases();
        $this->_registerLongLivedBoundaries();
        $this->_registerServerMessages();
    }

    private function _registerBladeDirective(): void
    {
        Blade::directive('t', fn (string $expression) => "<?php echo e(t($expression)); ?>");
    }

    private function _registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('langsys.locale', DetectLocale::class);
        $router->aliasMiddleware('langsys.flush', FlushPendingRegistrations::class);

        // Deliberately not added to any group: automatic response translation
        // is opt-in per route/group and must never coexist with @t tagging.
        $router->aliasMiddleware('langsys.translate-page', TranslateResponse::class);

        // The locale cookie is a plain locale code, not a secret. Keeping it
        // unencrypted lets DetectLocale read it back and lets client-side JS
        // share the same preference.
        $this->app->resolving(
            EncryptCookies::class,
            fn (EncryptCookies $middleware) => $middleware->disableFor(config('langsys.locale.cookie'))
        );
    }

    /**
     * In migrate mode Laravel builds this package's validator, so a failure is rendered from the
     * rule that failed rather than from a lang file. In keep mode nothing is installed at all and
     * Laravel answers exactly as it would without this package.
     */
    private function _registerServerMessages(): void
    {
        if (config('langsys.localization') !== 'migrate') {
            return;
        }

        $this->app['validator']->resolver(
            fn ($translator, $data, $rules, $messages, $attributes) => new MessageValidator($translator, $data, $rules, $messages, $attributes)
        );

        // Through the kernel, not the router: the kernel writes the groups onto the router when
        // it is constructed, which happens after this provider boots and would drop anything
        // pushed straight to the router. Appended, so it wraps the route's own stack and sees the
        // response Laravel's pipeline rendered from a ValidationException, validator attached.
        $this->app->booted(function () {
            if (!$this->app->bound(HttpKernel::class)) {
                return;
            }

            $kernel = $this->app->make(HttpKernel::class);

            if (!method_exists($kernel, 'appendMiddlewareToGroup') || !method_exists($kernel, 'getMiddlewareGroups')) {
                return;
            }

            // Only groups the application actually defines: the kernel throws on any other, and an
            // application is free to ship without an `api` group.
            foreach (array_intersect(['web', 'api'], array_keys($kernel->getMiddlewareGroups())) as $group) {
                $kernel->appendMiddlewareToGroup($group, AttachServerMessages::class);
            }
        });
    }

    /**
     * The Client is a container singleton, and both Octane workers and queue
     * workers keep the container across units of work. Without an explicit
     * boundary the next request or job inherits this one's write decision
     * (GATE-3) and its in-memory catalog (SRV-2), and discovered phrases wait
     * for the worker to exit. PHP-FPM needs none of this: the process ends the
     * scope, and FlushPendingRegistrations::terminate() sends the queue.
     */
    private function _registerLongLivedBoundaries(): void
    {
        $boundaries = [JobProcessed::class, JobExceptionOccurred::class];

        // Octane is optional. Listening by class name costs nothing without it.
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $boundaries[] = \Laravel\Octane\Events\RequestTerminated::class;
        }

        $this->app['events']->listen($boundaries, fn () => $this->_endRequestScope());
    }

    /**
     * Only a Client this unit of work actually used: resolving one here would
     * build it, and without credentials its constructor throws — on every job
     * and every Octane request of an app that has not configured Langsys.
     *
     * Flush before reset. resetRequestState() does not send the queue, and a
     * reset first would judge this unit's discoveries against a cleared write
     * decision. Neither call can throw — the SDK catches every failure inside
     * both — so nothing here guards them.
     */
    private function _endRequestScope(): void
    {
        if (!$this->app->resolved(Client::class)) {
            return;
        }

        $client = $this->app->make(Client::class);
        $client->flushPendingRegistrations();
        $client->resetRequestState();
    }
}
