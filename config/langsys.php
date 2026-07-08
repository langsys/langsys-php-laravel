<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Langsys project credentials
    |--------------------------------------------------------------------------
    |
    | Use a WRITE key in development so new phrases and content blocks are
    | auto-registered as your views render, and a READ-ONLY key in production.
    | The key type is detected server-side — there is no local toggle.
    |
    */

    'api_key'    => env('LANGSYS_API_KEY'),
    'project_id' => env('LANGSYS_PROJECT_ID'),
    'api_url'    => env('LANGSYS_API_URL', 'https://api.langsys.dev/api'),

    /*
    |--------------------------------------------------------------------------
    | Translation catalog cache
    |--------------------------------------------------------------------------
    |
    | The catalog is cached through Laravel's cache. `store` selects a store
    | from config/cache.php (null = the default store). The SDK's own file and
    | redis drivers are bypassed entirely.
    |
    */

    'cache' => [
        'store'  => env('LANGSYS_CACHE_STORE'),
        'prefix' => env('LANGSYS_CACHE_PREFIX', 'langsys:'),
        'ttl'    => (int) env('LANGSYS_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale detection (DetectLocale middleware)
    |--------------------------------------------------------------------------
    |
    | Sources are tried in order; the first hit wins. `persist` stores an
    | explicit choice (query source) so later requests keep it: 'cookie',
    | 'session', or null. `supported` restricts accepted locales (empty array
    | accepts anything).
    |
    */

    'locale' => [
        'sources'        => ['query', 'cookie', 'session', 'header'],
        'query_param'    => 'locale',
        'cookie'         => 'langsys_locale',
        'session_key'    => 'langsys_locale',
        'persist'        => 'cookie',
        'supported'      => [],
        'cookie_minutes' => 525600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pending-registration auto flush
    |--------------------------------------------------------------------------
    |
    | Phrases discovered during a request (write keys only) are queued and
    | flushed to the Langsys API after the response is sent, via the
    | FlushPendingRegistrations terminable middleware and the Octane
    | RequestTerminated listener. Disable to flush manually.
    |
    */

    'auto_flush' => (bool) env('LANGSYS_AUTO_FLUSH', true),

];
