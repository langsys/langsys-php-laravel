<?php

namespace Langsys\Laravel\Support;

use Illuminate\Contracts\Container\Container;
use Langsys\SDK\Client;

/**
 * Whether a runtime site asks the Langsys client at all. An app runs without an API key and a
 * project id in its tests, in CI and in local development. There the catalog is empty by
 * definition, so FRM-3's chain — catalog, lang files, source — is exactly what Laravel answers on
 * its own, and every runtime site defers to it rather than building a client.
 *
 * With `require_credentials` on, the app has said a missing key is a mistake, so the answer is
 * yes: the client is built and its first use throws the core's `ConfigurationException` (WIRE-4).
 * Nothing asks while Laravel boots, so booting never throws.
 *
 * Public: an app that translates something itself — its own error envelope through
 * `Client::translateMessage()` — asks it first, and falls back to the source when it says no.
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

        // Read as the core reads it, so the string "false" is off here too.
        if (filter_var($config->get('langsys.require_credentials'), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

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
