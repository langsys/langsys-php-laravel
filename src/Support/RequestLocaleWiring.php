<?php

namespace Langsys\Laravel\Support;

/**
 * The Client's `request_locale` options: where this app keeps the values SRV-6 reads (BIND-4), for
 * the SDK's own fallback when something asks the Client for a locale nobody set.
 */
final class RequestLocaleWiring
{
    public static function options(): array
    {
        return [
            'query_param' => config('langsys.locale.query_param'),
            'cookie'      => config('langsys.locale.cookie'),
            'session_key' => config('langsys.locale.session_key'),
            // Laravel apps route locales through their own middleware, not the SDK's URL reading.
            'path'        => false,
            'subdomain'   => false,
            // The SDK sends Vary through PHP's header(), which Laravel's response does not carry
            // and an Octane worker never emits. DetectLocale puts it on the response instead.
            'send_vary'   => false,
        ];
    }

    /** The SDK's negotiation over Accept-Language alone, with the header left to Laravel's response. */
    public static function headerOnly(): array
    {
        return ['query_param' => null, 'cookie' => null, 'session_key' => null, 'path' => false, 'subdomain' => false, 'send_vary' => false];
    }
}
