<?php

namespace Langsys\Laravel\Http;

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

    public static function current(): string
    {
        if (self::$sending > 0 || !app()->bound('request')) {
            return self::SERVER;
        }

        return self::of(app('request'));
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
