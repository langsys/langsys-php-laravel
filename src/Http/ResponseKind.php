<?php

namespace Langsys\Laravel\Http;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\NotificationSender;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Routing\Route;

/**
 * What the response being served is (FRM-4), decided from Laravel's own structure: a page the
 * server renders, a page its own browser SDK translates (an Inertia visit, or a route behind
 * Inertia's middleware asked for a page), or an API response (a request expecting JSON). The app can
 * name the kind per route group in `langsys.response_kinds` (`auto`, `server`, `client`).
 *
 * Mail and notifications are read by their recipient, not by the page being served, so while one
 * is rendered the answer is always the server's, whatever the request.
 */
final class ResponseKind
{
    public const SERVER = 'server';
    public const CLIENT = 'client';
    public const API = 'api';

    public static function current(): string
    {
        if (!app()->bound('request')) {
            return self::SERVER;
        }

        $kind = self::of(app('request'));

        // FRM-4: mail or a notification sent while serving a client page is read by its recipient.
        return $kind === self::CLIENT && self::forRecipient() ? self::SERVER : $kind;
    }

    /**
     * Whether what is rendering now is read by a recipient: mail, a notification, or what a queued
     * job writes, run inside the request or not. The call stack says: a Mailable's `send()`
     * (envelope, subject and Blade body), its `render()` preview, the mailer sending a view with no
     * Mailable (`Mail::send('emails.x', …)`), a Markdown mail rendered on its own
     * (`MailMessage::render()`), a notification on any channel, or a job's handler. Laravel has
     * no event before mail renders, and a notification a listener cancels fires none after, so
     * only the stack is right every time. The whole stack is read, since each nested view or
     * component adds frames between mail and what it renders; a frame carries its class without
     * `DEBUG_BACKTRACE_PROVIDE_OBJECT`. It is asked by `__()` only on a client page, where
     * server-side `__()` is rare, and by Blade once per tree of views.
     */
    public static function forRecipient(): bool
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($frames as $frame) {
            if (isset($frame['class']) && (is_a($frame['class'], Mailable::class, true) || is_a($frame['class'], Mailer::class, true) || is_a($frame['class'], NotificationSender::class, true) || is_a($frame['class'], Markdown::class, true) || is_a($frame['class'], CallQueuedHandler::class, true))) {
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

        // An Inertia visit announces itself. A JSON call a page makes to a route behind Inertia's
        // middleware is read by that call, not by a page, so only what neither is the route decides.
        if ($request->header('X-Inertia')) {
            return self::CLIENT;
        }

        if ($request->expectsJson()) {
            return self::API;
        }

        return self::_isInertiaRoute($route) ? self::CLIENT : self::SERVER;
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
