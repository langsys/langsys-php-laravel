<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Langsys project credentials
    |--------------------------------------------------------------------------
    |
    | `php artisan langsys:sync` needs a WRITE key to register your phrases;
    | serve with a READ-ONLY key. The key type is detected server-side — there
    | is no local toggle.
    |
    */

    'api_key'    => env('LANGSYS_API_KEY'),
    'project_id' => env('LANGSYS_PROJECT_ID'),
    'api_url'    => env('LANGSYS_API_URL', 'https://api.langsys.dev/api'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Once installed, Langsys answers Laravel's own translate function:
    | `__()`, `trans()`, `trans_choice()` and `@lang` return the catalog's
    | translation, else your lang files' translation, else the source. With
    | nothing in the catalog that is exactly what Laravel returns on its own.
    | Validation failures also carry entries a client SDK can translate. Turn
    | it off to debug with plain Laravel: nothing of this package is installed.
    |
    */

    'enabled' => env('LANGSYS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Response kinds
    |--------------------------------------------------------------------------
    |
    | What `__()` returns depends on who reads the response next. A page the
    | server renders gets the translation; an Inertia page gets the source,
    | because its own browser SDK translates it; mail and notifications always
    | get the translation, in the recipient's language. This is read from
    | Laravel's own structure. Name a route group here to decide it yourself:
    | 'auto', 'server' or 'client', e.g. ['admin' => 'server'].
    |
    */

    'response_kinds' => [],

    /*
    |--------------------------------------------------------------------------
    | Declared value sets
    |--------------------------------------------------------------------------
    |
    | A sentence that names a translatable value — a status, a category — is
    | registered once per value, with the word written in, so it is translated
    | whole: `The order is Shipped.` A backed enum marked
    | #[\Langsys\SDK\Messages\TranslatesAs('status')], and any class
    | implementing \Langsys\SDK\Messages\TranslatableValues, is found in
    | app/ without listing it. List classes kept elsewhere here.
    |
    */

    'value_sets' => [],

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    |
    | `php artisan langsys:sync` registers every literal `__()`, `trans()`,
    | `trans_choice()`, `@lang` and `t()` in your PHP and Blade, and every line
    | of your base-language files, with the translations your other lang
    | files already have. Nothing registers while serving a request. It reads
    | app/, routes/ and resources/views/; list other directories here to
    | replace them.
    |
    */

    'sync_paths' => [],

    /*
    |--------------------------------------------------------------------------
    | Catalog snapshot
    |--------------------------------------------------------------------------
    |
    | A snapshot file exported from Langsys. The client reads it before the
    | network, so a render has translations with no API call, and a phrase it
    | lacks falls back to the live catalog. It is a cache: export it again to
    | refresh it, never edit it. A file that fails to load is reported and
    | skipped.
    |
    */

    'snapshot' => env('LANGSYS_SNAPSHOT'),

    /*
    |--------------------------------------------------------------------------
    | Server messages
    |--------------------------------------------------------------------------
    |
    | The category every message template is registered and looked up under. It
    | has to match what your JS SDKs pass to t(), or a client looks up a phrase
    | the server filed elsewhere and always falls back to the source text.
    |
    */

    'messages' => [
        'category' => env('LANGSYS_MESSAGES_CATEGORY', 'Errors'),

        /*
         * Where the entries sit in your error responses. Laravel's own body is
         * untouched — `message` and `errors` keep their shape and their text —
         * and the entries travel beside them under this key, for a client SDK
         * to render translated. Set it empty when your API has its own error
         * envelope: nothing is attached, and your handler reads the entries from
         * the ValidationException's validator (`serverMessages()`) and
         * translates them with `Client::translateMessage()`.
         */
        'response_key' => env('LANGSYS_MESSAGES_RESPONSE_KEY', 'langsys_errors'),

        /*
         * The names of an entry's pieces, where your client expects others:
         * ['template' => 'text', 'field' => 'path']. Unlisted pieces keep
         * their names: field, code, message, template, params.
         */
        'pieces' => [],
    ],

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
    | Laravel's locale comes first: when your app has already set it (its own
    | middleware, a user preference), that locale is used. Otherwise the
    | sources are tried in order, the first usable one wins, and the response
    | carries the Vary header that choice requires. A candidate must be a
    | locale your Langsys project serves; `supported` narrows that further
    | (empty accepts every project locale). `persist` keeps a query-string
    | choice for later requests: 'cookie', 'session', or null.
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
    | `__()` needed. This is the only way to cover text Alpine injects from a JS
    | expression (`x-text="'Save changes'"`), which never becomes a DOM node you
    | can wrap.
    |
    | PICK ONE PER ROUTE — this, OR text translated through `__()` / `@lang`,
    | never both. If both run, this middleware re-walks text `__()` already
    | translated, looks the TRANSLATED string up as a source phrase, misses, and
    | registers it: a Spanish "Guardar" enters the catalog every Langsys SDK
    | shares as though it were source text. Mark any already-resolved subtree
    | `translate="no"`.
    |
    | If you server-render with this AND hydrate with a Langsys JS SDK, pair it
    | with a JS version whose tokenizer recognises `data-langsys-phrase`; older
    | versions re-walk server-tokenized subtrees and split phrases at tag
    | boundaries, fragmenting the shared catalog silently.
    |
    | `only`/`except` take Laravel path patterns (`admin/*`); `except` wins.
    |
    */

    'translate_response' => [
        'enabled'  => (bool) env('LANGSYS_TRANSLATE_RESPONSE', false),
        'category' => env('LANGSYS_TRANSLATE_RESPONSE_CATEGORY'),
        'only'     => [],
        'except'   => [],
    ],

];
