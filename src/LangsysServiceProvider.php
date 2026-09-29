<?php

namespace Langsys\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Langsys\Laravel\Console\MessagesCommand;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
use Langsys\Laravel\Cache\LaravelCacheAdapter;
use Langsys\Laravel\Http\Middleware\AttachServerMessages;
use Langsys\Laravel\Http\Middleware\DetectLocale;
use Langsys\Laravel\Http\Middleware\FlushPendingRegistrations;
use Langsys\Laravel\Http\Middleware\TranslateResponse;
use Langsys\Laravel\Messages\MessageValidator;
use Langsys\Laravel\Support\RequestLocaleWiring;
use Langsys\Laravel\Translation\CatalogTranslator;
use Langsys\Laravel\Translation\MigrationFiles;
use Langsys\SDK\Client;
use Langsys\SDK\Snapshot\Snapshot;
use Throwable;

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
                'request_locale'    => RequestLocaleWiring::options(),
                'snapshot'          => self::_snapshot($config['snapshot'] ?? null),
                'migration'         => $config['enabled']
                    ? MigrationFiles::for($app['translation.loader'], $app->langPath(), $app['config']['app.fallback_locale'])
                    : null,
                'cache'             => new LaravelCacheAdapter(
                    $app['cache']->store($config['cache']['store']),
                    $config['cache']['prefix'],
                    $config['cache']['ttl'],
                    $config['project_id'],
                ),
            ]);
        });

        $this->app->singleton(LangsysTranslator::class);

        $this->_registerCatalogTranslator();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/langsys.php' => config_path('langsys.php'),
        ], 'langsys-config');

        if ($this->app->runningInConsole()) {
            $this->commands([MessagesCommand::class]);
        }

        $this->_registerMiddlewareAliases();
        $this->_registerLongLivedBoundaries();
        $this->_registerServerMessages();
    }

    /**
     * The core loads and checks the snapshot (SNAP-2, SNAP-3). One it refuses is reported and left
     * out: the client then reads the live catalog, as it would with no snapshot at all.
     */
    private static function _snapshot(?string $path): ?Snapshot
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            return Snapshot::load($path);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function _registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('langsys.locale', DetectLocale::class);

        // The app resolved the locale if anything set it before DetectLocale runs (SRV-6).
        $this->app['events']->listen(
            LocaleUpdated::class,
            fn () => $this->app['request']->attributes->set(DetectLocale::RESOLVED, true)
        );
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
     * Laravel's `translator` is this package's subclass (FRM-1), so every `__()` in the application
     * and its packages is answered from the catalog without a call site changing. The loader,
     * locale and fallback are Laravel's own; only where a line comes from differs. Off, Laravel's
     * own translator is left in place.
     */
    private function _registerCatalogTranslator(): void
    {
        // Decided when the translator is built, not here: configuration is final by then.
        $this->app->extend('translator', function (Translator $translator, Application $app) {
            if (!$app['config']['langsys.enabled']) {
                return $translator;
            }

            $catalog = new CatalogTranslator($translator->getLoader(), $translator->getLocale(), fn () => $app->make(LangsysTranslator::class));
            $catalog->setFallback($translator->getFallback());

            return $catalog;
        });
    }

    /**
     * Laravel builds this package's validator, so each failure also carries an entry built from the
     * rule that failed, which a client SDK can translate. Off, nothing is installed and Laravel
     * answers exactly as it would without this package.
     */
    private function _registerServerMessages(): void
    {
        if (!config('langsys.enabled')) {
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
     * Flush before reset. resetRequestState() drops whatever is still queued —
     * one unit's phrases never ride another's send (REG-8) — so a reset first
     * would discard this unit's discoveries unsent. Neither call can throw — the SDK catches every failure inside
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
