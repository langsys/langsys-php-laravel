<?php

if (!function_exists('t')) {
    /**
     * `__()` by a shorter name, with the same arguments and the same answer. Laravel's own
     * translate function is the SDK's (FRM-1); this adds no second one.
     */
    function t($key = null, $replace = [], $locale = null)
    {
        return __($key, $replace, $locale);
    }
}
