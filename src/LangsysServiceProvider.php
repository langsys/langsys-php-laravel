<?php

namespace Langsys\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Langsys\Laravel\Cache\LaravelCacheAdapter;
use Langsys\Laravel\Http\Middleware\DetectLocale;
use Langsys\Laravel\Http\Middleware\FlushPendingRegistrations;
use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;

class LangsysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/langsys.php', 'langsys');

        $this->app->singleton(Client::class, function (Application $app) {
            $config = $app['config']['langsys'];

            return new Client($config['api_key'], $config['project_id'], [
                'api_url' => $config['api_url'],
                'cache'   => new LaravelCacheAdapter(
                    $app['cache']->store($config['cache']['store']),
                    $config['cache']['prefix'],
                    $config['cache']['ttl'],
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
        $this->_registerOctaneFlush();
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

        // The locale cookie is a plain locale code, not a secret. Keeping it
        // unencrypted lets DetectLocale read it back and lets client-side JS
        // share the same preference.
        $this->app->resolving(
            EncryptCookies::class,
            fn (EncryptCookies $middleware) => $middleware->disableFor(config('langsys.locale.cookie'))
        );
    }

    /**
     * Octane workers are long-lived: PHP's shutdown handler never fires
     * between requests, so without this listener a write-key queue would leak
     * discovered tokens across requests (and tenants). Flush after every
     * Octane request; the middleware's terminate() already covers classic
     * PHP-FPM.
     */
    private function _registerOctaneFlush(): void
    {
        if (!class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            return;
        }

        $this->app['events']->listen(\Laravel\Octane\Events\RequestTerminated::class, function () {
            if (!config('langsys.auto_flush')) {
                return;
            }

            $client = $this->app->make(Client::class);

            if (!$client->hasPendingRegistrations()) {
                return;
            }

            try {
                $client->flushPendingRegistrations();
            } catch (LangsysException) {
                // Token registration must never break the worker.
            }
        });
    }
}
