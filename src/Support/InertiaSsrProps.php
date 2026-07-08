<?php

namespace Langsys\Laravel\Support;

use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;

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
        $client = app(Client::class);
        $canonical = LocaleFormatter::canonicalize($locale ?? app()->getLocale());

        return [
            'langsys' => [
                'initialTranslations'       => $client->getTranslations(LocaleDetector::normalize($canonical)),
                'initialTranslationsLocale' => $canonical,
            ],
        ];
    }
}
