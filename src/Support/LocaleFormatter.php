<?php

namespace Langsys\Laravel\Support;

use Locale;

/**
 * Canonical BCP 47 casing for Laravel's own locale store:
 * 'es-es' / 'pt_br' → 'es-ES' / 'pt-BR', 'zh-hant-tw' → 'zh-Hant-TW'.
 *
 * DetectLocale uses it for the locale it keeps in the session or the cookie,
 * which a later request reads back as it would any locale, and for
 * app()->setLocale() where the app's own `lang/` has no spelling of its own for
 * the locale. Both the PHP and the JS SDK identify a locale by lowercase `xx-yy`
 * (WIRE-3): the core normalizes every spelling it is handed, and where the
 * binding hands a locale to another SDK or the wire it writes that form with
 * LocaleDetector::normalize().
 */
class LocaleFormatter
{
    public static function canonicalize(string $locale): string
    {
        if ($locale === '') {
            return $locale;
        }

        if (class_exists(Locale::class)) {
            $canonical = Locale::canonicalize($locale);

            if ($canonical) {
                return str_replace('_', '-', $canonical);
            }
        }

        [$language, $region] = array_pad(explode('-', str_replace('_', '-', $locale), 2), 2, null);

        return $region === null ? strtolower($language) : strtolower($language) . '-' . strtoupper($region);
    }
}
