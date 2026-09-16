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
    | Localization mode
    |--------------------------------------------------------------------------
    |
    | Which layer answers Laravel's own localization calls.
    |
    | keep    — the default, and this package stays out of the way entirely.
    |           Your lang files remain authoritative, and validation messages,
    |           `__()` and the 422 body are exactly what Laravel produces on its
    |           own.
    | migrate — the zero-file model. A validation message is built from the rule
    |           that failed, with the field's label written into the sentence,
    |           and registered for translation. What the server sends stays
    |           Laravel's own text; a client renders the translation from the
    |           entry that travels beside it.
    |
    | Read docs/server-messages.md before switching. It covers what moves where,
    | and what is translated fresh. (A third mode, `fill`, where Laravel answers
    | and Langsys covers only what your lang files miss, is planned and not
    | implemented yet.)
    |
    */

    'localization' => env('LANGSYS_LOCALIZATION', 'keep'),

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
         * to render translated.
         */
        'response_key' => env('LANGSYS_MESSAGES_RESPONSE_KEY', 'langsys_errors'),
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
    |
    */

    'translate_response' => [
        'enabled'  => (bool) env('LANGSYS_TRANSLATE_RESPONSE', false),
        'category' => env('LANGSYS_TRANSLATE_RESPONSE_CATEGORY'),
        'only'     => [],
        'except'   => [],
    ],

];
