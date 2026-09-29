<?php

namespace Langsys\Laravel\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

/** An application's own locale middleware, running before DetectLocale: it sets Laravel's locale from a header. */
class SetsAppLocale
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->hasHeader('X-App-Locale')) {
            app()->setLocale($request->header('X-App-Locale'));
        }

        return $next($request);
    }
}
