<?php

namespace Langsys\Laravel\Support;

use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;
use Throwable;

/**
 * Builds the SSR seeding payload for the Langsys JS SDKs. Share it from
 * HandleInertiaRequests::share() (or Inertia::share()) and feed it straight
 * into LangsysApp.init() on the client:
 *
 *   // app/Http/Middleware/HandleInertiaRequests.php
 *   public function share(Request $request): array
 *   {
 *       return [...parent::share($request), ...InertiaSsrProps::share()];
 *   }
 *
 *   // resources/js (Vue/React page layout)
 *   LangsysApp.init({ ..., initialTranslations: props.langsys.initialTranslations,
 *                     initialTranslationsLocale: props.langsys.initialTranslationsLocale });
 *
 * The catalog comes from Client::getTranslations(), whose category → phrase →
 * translation map is the iCategories shape the JS SDKs' initialTranslations
 * option expects — the client then skips its initial fetch entirely.
 */
class InertiaSsrProps
{
    public static function share(?string $locale = null): array
    {
        // One locale form toward every SDK (WIRE-3): the lowercase `xx-yy` the
        // PHP SDK keys its catalog by, which is also what the JS SDK
        // canonicalizes any locale it is handed to.
        $locale = LocaleDetector::normalize($locale ?? app()->getLocale());

        try {
            // The same call t() reads through, so within a request this is the
            // catalog the page was rendered with, answered from the SDK's
            // request-scoped memory rather than fetched again (SRV-4).
            $catalog = app(Client::class)->getTranslations($locale);
        } catch (Throwable $e) {
            // getTranslations() throws on an unreachable API rather than answer
            // with an empty catalog, and this runs on every Inertia request, so
            // an outage must not become a 500 here (WIRE-4). No seed rather than
            // an empty one: the JS SDK marks a seeded locale loaded and skips its
            // own fetch, while no seed lets it fetch as usual. The server could
            // not read a catalog either and rendered source text, so the first
            // client render still agrees with it.
            report($e);

            return ['langsys' => ['initialTranslations' => null, 'initialTranslationsLocale' => null]];
        }

        return [
            'langsys' => [
                'initialTranslations'       => $catalog,
                'initialTranslationsLocale' => $locale,
            ],
        ];
    }
}
