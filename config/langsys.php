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
    | Automatic response translation (TranslateResponse middleware)
    |--------------------------------------------------------------------------
    |
    | Opt-in. Applies the `langsys.translate-page` middleware to translate every
    | text node and translatable attribute of a rendered HTML response, with no
    | `@t` tagging. This is the only way to cover text Alpine injects from a JS
    | expression (`x-text="'Save changes'"`), which never becomes a DOM node you
    | can wrap.
    |
    | PICK ONE MODE PER PROJECT — automatic OR `@t` tagging, never both. If both
    | run, this middleware re-walks nodes `@t` already translated, looks the
    | TRANSLATED string up as a source phrase, misses, and registers it: a
    | Spanish "Guardar" enters the catalog every Langsys SDK shares as though it
    | were source text. Mark any already-resolved subtree `translate="no"`.
    |
    | If you server-render with this AND hydrate with a Langsys JS SDK, pair it
    | with a JS version whose tokenizer recognises `data-langsys-phrase`; older
    | versions re-walk server-tokenized subtrees and split phrases at tag
    | boundaries, fragmenting the shared catalog silently.
    |
    | `only`/`except` take Laravel path patterns (`admin/*`); `except` wins.
    | Caching is keyed by the source HTML, so it is useless for pages carrying a
    | CSRF token or timestamp — hence disabled by default.
    |
    */

    'translate_response' => [
        'enabled'  => (bool) env('LANGSYS_TRANSLATE_RESPONSE', false),
        'category' => env('LANGSYS_TRANSLATE_RESPONSE_CATEGORY'),
        'only'     => [],
        'except'   => [],
        'cache'    => [
            'enabled' => (bool) env('LANGSYS_TRANSLATE_RESPONSE_CACHE', false),
            'ttl'     => (int) env('LANGSYS_TRANSLATE_RESPONSE_CACHE_TTL', 3600),
        ],
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
