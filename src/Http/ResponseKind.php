<?php

namespace Langsys\Laravel\Http;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * What the response being served is (FRM-4), decided from Laravel's own structure: a page the
 * server renders, a page its own browser SDK translates (Inertia), or an API response. The app can
 * name the kind per route group in `langsys.response_kinds` (`auto`, `server`, `client`).
 *
 * A notification being sent is read by its recipient, not by the page being served, so while one
 * is sent the answer is always the server's, whatever the request.
 */
final class ResponseKind
{
    public const SERVER = 'server';
    public const CLIENT = 'client';
    public const API = 'api';

    /** Notifications being sent right now; Laravel's own events open and close each one. */
    private static int $sending = 0;

    /**
     * Frames read looking for a Mailable. Measured: from `__()` in a Mailable's Blade view — a
     * layout with an `@include` — up to `Mailable::send()` is 26 frames; each nested view adds about
     * four. 64 leaves room for some nine more levels of nesting.
     */
    private const MAILABLE_DEPTH = 64;

    public static function current(): string
    {
        if (self::$sending > 0 || !app()->bound('request')) {
            return self::SERVER;
        }

        $kind = self::of(app('request'));

        // FRM-4: a Mailable sent or previewed while serving a client page is read by its recipient.
        return $kind === self::CLIENT && self::_insideMailable() ? self::SERVER : $kind;
    }

    /**
     * Laravel has no event before mail renders, so the call stack says whether `__()` was reached
     * from it: a Mailable's `send()` (envelope, subject and Blade body), its `render()` preview, or
     * the mailer sending a view with no Mailable (`Mail::send('emails.x', …)`). A
     * frame carries its class without `DEBUG_BACKTRACE_PROVIDE_OBJECT`, and the depth is bounded:
     * asked only on a client page, where server-side `__()` is rare.
     */
    private static function _insideMailable(): bool
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::MAILABLE_DEPTH);
        foreach ($frames as $frame) {
            if (isset($frame['class']) && (is_a($frame['class'], Mailable::class, true) || is_a($frame['class'], Mailer::class, true))) {
                return true;
            }
        }

        return false;
    }

    public static function of(Request $request): string
    {
        $route = $request->route();

        if (!$route instanceof Route) {
            return self::SERVER;
        }

        foreach ((array) config('langsys.response_kinds', []) as $group => $kind) {
            if ($kind !== 'auto' && in_array($group, $route->middleware(), true)) {
                return $kind;
            }
        }

        if ($request->header('X-Inertia') || self::_isInertiaRoute($route)) {
            return self::CLIENT;
        }

        return $request->expectsJson() ? self::API : self::SERVER;
    }

    public static function sending(): void
    {
        self::$sending++;
    }

    public static function sent(): void
    {
        self::$sending = max(0, self::$sending - 1);
    }

    /** A route behind the application's Inertia middleware renders an Inertia page, first load included. */
    private static function _isInertiaRoute(Route $route): bool
    {
        if (!class_exists(\Inertia\Middleware::class)) {
            return false;
        }

        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            $class = is_string($middleware) ? explode(':', $middleware, 2)[0] : null;

            if ($class !== null && is_a($class, \Inertia\Middleware::class, true)) {
                return true;
            }
        }

        return false;
    }
}
