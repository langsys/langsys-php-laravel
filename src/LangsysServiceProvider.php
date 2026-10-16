<?php

namespace Langsys\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Langsys\Laravel\Console\CacheCommand;
use Langsys\Laravel\Console\ClearCommand;
use Langsys\Laravel\Console\MessagesCommand;
use Langsys\Laravel\Console\SyncCommand;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
use Langsys\Laravel\Cache\LaravelCacheAdapter;
use Langsys\Laravel\Http\Middleware\AttachServerMessages;
use Langsys\Laravel\Http\Middleware\MarkResolvedPage;
use Langsys\Laravel\Http\Middleware\DetectLocale;
use Langsys\Laravel\Http\Middleware\FlushPendingRegistrations;
use Langsys\Laravel\Http\Middleware\TranslateResponse;
use Langsys\Laravel\Messages\MessageValidator;
use Langsys\Laravel\Support\ClientState;
use Langsys\Laravel\Support\RequestLocaleWiring;
use Langsys\Laravel\Support\ValueSetDiscovery;
use Langsys\Laravel\Translation\CatalogTranslator;
use Langsys\Laravel\Translation\MigrationFiles;
use Langsys\Laravel\View\ValueMarker;
use Langsys\SDK\Client;
use Langsys\SDK\Snapshot\Snapshot;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LangsysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/langsys.php', 'langsys');

        $this->app->singleton(Client::class, function (Application $app) {
            $config = $app['config']['langsys'];

            $client = new Client($config['api_key'], $config['project_id'], [
                'api_url'           => $config['api_url'],
                'require_credentials' => $config['require_credentials'],
                // FRM-2: what passes through `__()` or the facade registers through
                // `langsys:sync`, the package on or off; the core alone registers a sentence
                // written in from a declared value the last sync did not see (FRM-7).
                'runtime_registration' => false,
                'value_sets'        => $config['enabled'] ? ValueSetDiscovery::classes() : [],
                'messages_category' => $config['messages']['category'],
                'request_locale'    => RequestLocaleWiring::options(),
                'snapshot'          => $snapshot = self::_snapshot($config['snapshot'] ?? null),
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

            // Offline, the snapshot's locales are the ones served (SRV-6): DetectLocale reads them.
            if ($snapshot !== null) {
                $app->instance(Snapshot::class, $snapshot);
            }

            // FRM-3: a catalog miss is answered by the app's own lang files before the source.
            if ($config['enabled']) {
                $client->useMissFallback(fn (string $phrase, string $locale, ?string $category, $argument) => $app['translator'] instanceof CatalogTranslator
                    ? $app['translator']->fallbackLine($phrase, $locale, $argument)
                    : null);
            }

            return $client;
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
            $this->commands([SyncCommand::class, MessagesCommand::class, CacheCommand::class, ClearCommand::class]);

            // Laravel 11.27+: `optimize` and `optimize:clear` run them, as they do event:cache.
            if (method_exists($this, 'optimizes')) {
                $this->optimizes(optimize: 'langsys:cache', clear: 'langsys:clear', key: 'langsys');
            }
        }

        $this->_registerTranslateDirectives();
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

    /**
     * `@lang`, its alias `@t` and `@choice` print raw, as Laravel's do, but text from the catalog is
     * rendered safe (FRM-8): see `CatalogTranslator::getHtml()` and `choiceHtml()`. The block form,
     * `@lang ... @endlang` and `@t ... @endt`, prints its text through `getHtml()` as well, with the
     * values its opening tag names; each block keeps those on a stack of its own, so blocks nest.
     *
     * Laravel keeps a compiled view until its template changes, whatever the configuration, so
     * what a view compiles to is the same on and off, and decides as it renders: switched off,
     * Laravel's own translator answers `getHtml()` with `get()` and `choiceHtml()` with `choice()`,
     * which are its own directives, and a marked value prints as Blade prints it.
     */
    private function _registerTranslateDirectives(): void
    {
        Translator::macro('getHtml', fn ($key, array $replace = [], $locale = null) => $this->get($key, $replace, $locale));
        Translator::macro('choiceHtml', fn ($key, $number, array $replace = [], $locale = null) => $this->choice($key, $number, $replace, $locale));

        $line = fn (string $expression) => "<?php echo app('translator')->getHtml($expression); ?>";

        $lang = function (?string $expression) use ($line) {
            $expression = trim((string) $expression);

            return match (true) {
                $expression === ''              => '<?php $__lsBlock[] = []; ob_start(); ?>',
                str_starts_with($expression, '[') => "<?php \$__lsBlock[] = $expression; ob_start(); ?>",
                default                         => $line($expression),
            };
        };
        $end = fn () => "<?php echo app('translator')->getHtml(trim(ob_get_clean()), array_pop(\$__lsBlock)); ?>";

        Blade::directive('lang', $lang);
        Blade::directive('t', $lang);
        Blade::directive('endlang', $end);
        Blade::directive('endt', $end);
        Blade::directive('choice', fn (?string $expression) => "<?php echo app('translator')->choiceHtml($expression); ?>");

        // VAR-5: every value Blade prints in visible text is marked, so a reader turns it back into a
        // placeholder. The echo keeps Blade's own escaping, double encoding included.
        Blade::precompiler(function (string $template) {
            $compiler = $this->app['blade.compiler'];
            $doubleEncode = (fn () => $this->echoFormat)->call($compiler) === 'e(%s)';

            return ValueMarker::precompile($template, $doubleEncode, fn (string $expression) => config('langsys.enabled') && $this->app['log']->warning(
                "Blade prints `$expression` in text, which has no name of its own, so its placeholder is `value`: give it a variable whose name says what it is.",
                ['view' => $compiler->getPath()]
            ));
        });

        // A view a response serves as something other than HTML — a robots.txt, an XML feed —
        // reaches whatever reads that format as Blade printed it, so its markers come out. HTML
        // keeps them for its readers, and so does JSON, which carries HTML a page inserts.
        $this->app['events']->listen(RequestHandled::class, fn (RequestHandled $event) => self::_unmarkUnlessHtml($event->response));
    }

    private static function _unmarkUnlessHtml(Response $response): void
    {
        $type = strtolower((string) $response->headers->get('Content-Type'));
        // A download is no page, whatever type it declares; Symfony fills in `text/html` for none.
        $download = str_contains(strtolower((string) $response->headers->get('Content-Disposition')), 'attachment');

        if ($response instanceof JsonResponse || $response instanceof BinaryFileResponse || (!$download && ($type === '' || str_contains($type, 'html')))) {
            return;
        }

        // A stream renders as it is sent, after this: what it renders then marks nothing.
        if ($response instanceof StreamedResponse) {
            $render = \Closure::bind(fn (StreamedResponse $stream) => $stream->callback, null, StreamedResponse::class)($response);

            if ($render !== null) {
                $response->setCallback(fn () => ValueMarker::withoutMarks($render));
            }

            return;
        }

        $content = $response->getContent();

        if (is_string($content) && str_contains($content, '<!--ls:')) {
            $response->setContent(ValueMarker::unmark($content));
        }
    }

    private function _registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('langsys.locale', DetectLocale::class);

        // The app resolved the locale if anything set it before DetectLocale runs (SRV-6).
        $this->app['events']->listen(
            LocaleUpdated::class,
            fn () => app('request')->attributes->set(DetectLocale::RESOLVED, true)
        );
        $router->aliasMiddleware('langsys.flush', FlushPendingRegistrations::class);

        // Deliberately not added to any group: automatic response translation
        // is opt-in per route/group and must never coexist with @t tagging.
        $router->aliasMiddleware('langsys.translate-page', TranslateResponse::class);

        // The locale cookie holds a plain locale code, not a secret, so it is
        // stored as the code itself rather than encrypted.
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

            // The current container's, asked at each call: under Octane the translator outlives the
            // request it was built in, and the client belongs to the request being served.
            $catalog = new CatalogTranslator($translator->getLoader(), $translator->getLocale(), fn () => ClientState::buildable() ? app(LangsysTranslator::class) : null);
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

            // A page the server renders is marked resolved (FRM-4, GATE-10); an API has no page.
            if (in_array('web', array_keys($kernel->getMiddlewareGroups()), true)) {
                $kernel->appendMiddlewareToGroup('web', MarkResolvedPage::class);
            }
        });
    }

    /**
     * The Client is a container singleton. A queue worker keeps its container
     * across jobs, and an Octane worker serves each request in a sandbox, a
     * clone of its container, so a client the worker resolved is shared by
     * every request and one a request resolved lives in its sandbox. The worker
     * builds it as it starts: the backoff clock is the long-lived object's and
     * outlives the request (REG-8, CACHE-2), so an outage costs one failing
     * fetch per window, not one per request. Without an explicit boundary the
     * next request or job inherits this one's write decision (GATE-3) and its
     * in-memory catalog (SRV-2), and discovered phrases wait for the worker to
     * exit. PHP-FPM needs none of this: the process ends the scope, and
     * FlushPendingRegistrations::terminate() sends the queue.
     */
    private function _registerLongLivedBoundaries(): void
    {
        $boundaries = [JobProcessed::class, JobExceptionOccurred::class];

        // Octane is optional. Listening by class name costs nothing without it.
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            // A request, a task (`Octane::concurrently()`) and a tick each run in a sandbox of their own.
            array_push($boundaries, \Laravel\Octane\Events\RequestTerminated::class, \Laravel\Octane\Events\TaskTerminated::class, \Laravel\Octane\Events\TickTerminated::class);

            $this->app['events']->listen(\Laravel\Octane\Events\WorkerStarting::class, fn (object $event) => ClientState::buildable($event->app) && $event->app->make(Client::class));
        }

        // Octane serves each request in a sandbox, a clone of the worker's container that holds
        // whatever the request resolved; its event carries it. A queue job runs in this container.
        // A job on a connection with the sync driver, whatever the app named it, runs inside whatever
        // dispatched it, a request or a command, which ends its own scope: its end sends nothing
        // while a request is being served.
        $this->app['events']->listen($boundaries, function (object $event) {
            $connection = $event->connectionName ?? null;

            if ($connection === null || ($connection !== 'sync' && config("queue.connections.$connection.driver") !== 'sync')) {
                self::_endRequestScope($event->sandbox ?? $this->app);
            }
        });
    }

    /**
     * Only a Client this unit of work holds: one it resolved, or under Octane
     * the one the worker built, which every sandbox shares. One that holds none
     * has nothing queued, and resolving it here would build a client on every
     * job for nothing.
     *
     * Flush before reset. resetRequestState() drops whatever is still queued —
     * one unit's phrases never ride another's send (REG-8) — so a reset first
     * would discard this unit's discoveries unsent. Neither call can throw — the SDK catches every failure inside
     * both — so nothing here guards them.
     */
    private static function _endRequestScope(Container $app): void
    {
        if (!$app->resolved(Client::class)) {
            return;
        }

        $client = $app->make(Client::class);
        $client->flushPendingRegistrations();
        $client->resetRequestState();
    }
}
