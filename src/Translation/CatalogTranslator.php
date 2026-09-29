<?php

namespace Langsys\Laravel\Translation;

use Closure;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Translation\Translator;
use Langsys\Laravel\LangsysTranslator;
use Langsys\SDK\Migration\LegacyValue;

/**
 * Laravel's translator with Langsys installed (FRM-1): `__()`, `trans()`, `@lang` and `trans_choice()`
 * keep their calling convention and are answered from the Langsys catalog (MIG-8).
 *
 * Resolving a key, converting its line and interpolating are the SDK's: the key goes to
 * `Client::translate()` with the replacements as params, and the SDK's migration mode turns it
 * into its source line. What this class adds is only what Laravel's side decides — which keys stay
 * with Laravel, the locale the lookup runs in, `count` for a choice, and which Laravel call a
 * sentence with no line of its own came through.
 */
class CatalogTranslator extends Translator
{
    /** @param  Closure(): LangsysTranslator  $langsys  Lazy: resolving the translator must not build a Client. */
    public function __construct(Loader $loader, string $locale, private readonly Closure $langsys)
    {
        parent::__construct($loader, $locale);
    }

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if (!is_string($key) || $key === '' || self::_isValidationKey($key)) {
            return parent::get($key, $replace, $locale, $fallback);
        }

        return $this->_translate($key, $replace, $locale, '__', null);
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        if (self::_isValidationKey($key)) {
            return parent::choice($key, $number, $replace, $locale);
        }

        if (is_countable($number)) {
            $number = count($number);
        }

        return $this->_translate($key, $replace, $locale, 'trans_choice', $number);
    }

    public function has($key, $locale = null, $fallback = true)
    {
        if (self::_isValidationKey($key)) {
            return parent::has($key, $locale, $fallback);
        }

        return ($this->langsys)()->client()->resolveLegacyKey($key) !== null;
    }

    /**
     * A key a file holds is the SDK's to resolve and convert. A miss is the argument as literal
     * source text (MIG-2), written in the syntax of the Laravel call that received it, and the SDK
     * reads a literal as Langsys syntax; so the miss is converted here, by the core's own table for
     * that entry point, and handed over already converted.
     */
    private function _translate(string $key, array $replace, ?string $locale, string $entryPoint, int|float|null $count): string
    {
        $langsys = ($this->langsys)();
        $client = $langsys->client();

        if ($client->resolveLegacyKey($key) !== null) {
            return $langsys->translate($key, null, $entryPoint === 'trans_choice' ? $replace + ['count' => $count] : $replace, $locale ?? $this->locale);
        }

        $call = LegacyValue::fromCall($key, $replace, $entryPoint, $count);

        if (!$call['recognised']) {
            $client->getLogger()->warning('A translation call\'s text is registered as written: it ' . $call['issue'], ['text' => $key]);
        }

        return $langsys->translate($call['text'], null, $call['params'], $locale ?? $this->locale);
    }

    /** Validation lines are the server-messages lane's: Laravel's validator reads them through here. */
    private static function _isValidationKey(mixed $key): bool
    {
        return is_string($key) && ($key === 'validation' || str_starts_with($key, 'validation.'));
    }
}
