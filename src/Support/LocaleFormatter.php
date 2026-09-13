<?php

namespace Langsys\Laravel\Support;

use Locale;

/**
 * Canonical BCP 47 casing for Laravel's own locale store:
 * 'es-es' / 'pt_br' → 'es-ES' / 'pt-BR', 'zh-hant-tw' → 'zh-Hant-TW'.
 *
 * Only DetectLocale uses it, for app()->setLocale(). Nothing handed to a
 * Langsys SDK goes through it: both the PHP and the JS SDK identify a locale
 * by lowercase `xx-yy` (WIRE-3), so every SDK boundary uses
 * LocaleDetector::normalize() instead.
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
