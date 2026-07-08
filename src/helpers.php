<?php

use Langsys\Laravel\LangsysTranslator;

if (!function_exists('t')) {
    /**
     * Translate a phrase through Langsys. Mirrors the JS SDKs' signature:
     * t($phrase, $category?, $params?, $locale?).
     */
    function t(string $phrase, ?string $category = null, array $params = [], ?string $locale = null): string
    {
        return app(LangsysTranslator::class)->translate($phrase, $category, $params, $locale);
    }
}
