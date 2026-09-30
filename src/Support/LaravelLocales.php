<?php

namespace Langsys\Laravel\Support;

use Langsys\SDK\Locale\LocaleDetector;

/**
 * Laravel names a locale's lang files as the app wrote it — `es`, `es_ES`, `es-ES` — while the SDK
 * identifies a locale by its lowercase `xx-yy` (WIRE-3). The spellings Laravel could use for one
 * SDK locale, the app's own first when it is known, then the bare language.
 */
final class LaravelLocales
{
    /** @return list<string> */
    public static function candidates(string $locale, ?string $preferred = null): array
    {
        $normal = LocaleDetector::normalize($locale);
        [$language, $region] = array_pad(explode('-', $normal, 2), 2, null);
        $candidates = $preferred !== null && LocaleDetector::normalize($preferred) === $normal ? [$preferred] : [];

        if ($region !== null) {
            array_push($candidates, $language . '_' . strtoupper($region), $language . '-' . strtoupper($region), $normal);
        }

        $candidates[] = $language;

        return array_values(array_unique($candidates));
    }

    /** The spelling this app's `lang/` directory actually uses for a locale, or null when it has none. */
    public static function inLangPath(string $langPath, string $locale): ?string
    {
        foreach (self::candidates($locale) as $candidate) {
            if (is_dir("$langPath/$candidate") || is_file("$langPath/$candidate.json")) {
                return $candidate;
            }
        }

        return null;
    }
}
