<?php

namespace Langsys\Laravel\Support;

use Illuminate\Contracts\Container\Container;
use Langsys\SDK\Client;

/**
 * Whether a Langsys client can be had without throwing. The core's constructor refuses to build
 * one without an API key and a project id, and an app runs without them in its tests, in CI and
 * in local development. There the catalog is empty by definition, so FRM-3's chain — catalog, lang
 * files, source — is exactly what Laravel answers on its own, and every runtime site defers to it
 * rather than building a client.
 *
 * @internal
 */
final class ClientState
{
    private static bool $noticed = false;

    public static function buildable(?Container $app = null): bool
    {
        $app ??= app();

        // A client already built, or one the app bound itself — a test's fake — needs no credentials.
        if ($app->resolved(Client::class)) {
            return true;
        }

        $config = $app['config'];

        if (filled($config->get('langsys.api_key')) && filled($config->get('langsys.project_id'))) {
            return true;
        }

        if (!self::$noticed) {
            self::$noticed = true;
            $app['log']->debug('Langsys has no API key or project id, so Laravel answers every translation until LANGSYS_API_KEY and LANGSYS_PROJECT_ID are set.');
        }

        return false;
    }
}
