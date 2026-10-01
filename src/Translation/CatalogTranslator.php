<?php

namespace Langsys\Laravel\Translation;

use Closure;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Translation\Translator;
use Langsys\Laravel\Http\ResponseKind;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Support\LaravelLocales;
use Langsys\SDK\Migration\LegacyValue;

/**
 * Laravel's translator with Langsys installed (FRM-1): `__()`, `trans()`, `@lang` and `trans_choice()`
 * keep their calling convention and are answered from the Langsys catalog, then the app's own lang
 * files, then the source (FRM-3).
 *
 * Resolving a key, converting its line, interpolating and choosing between catalog, lang file and
 * source are the SDK's: the call goes to `Client::resolve()` with the replacements as params. What
 * this class adds is only what Laravel's side decides — which keys stay with Laravel, the locale
 * the lookup runs in, `count` for a choice, which Laravel call a sentence came through, what the
 * response is (FRM-4), and how Laravel's own lang files answer the SDK's miss fallback.
 */
class CatalogTranslator extends Translator
{
    /** The call being resolved, for the miss fallback: [argument, replacements, entry point, count]. */
    private ?array $call = null;

    /**
     * @param  Closure(): ?LangsysTranslator  $langsys  Lazy: resolving the translator must not build a Client. Null
     *                                                  when none can be built, and Laravel answers.
     */
    public function __construct(Loader $loader, string $locale, private readonly Closure $langsys)
    {
        parent::__construct($loader, $locale);
    }

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if (!is_string($key) || $key === '' || self::_isValidationKey($key) || ($langsys = ($this->langsys)()) === null) {
            return parent::get($key, $replace, $locale, $fallback);
        }

        return $this->_resolve($langsys, $key, $replace, $locale, '__', null)['text'];
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        if (self::_isValidationKey($key) || ($langsys = ($this->langsys)()) === null) {
            return parent::choice($key, $number, $replace, $locale);
        }

        if (is_countable($number)) {
            $number = count($number);
        }

        return $this->_resolve($langsys, $key, $replace, $locale, 'trans_choice', $number)['text'];
    }

    public function has($key, $locale = null, $fallback = true)
    {
        if (self::_isValidationKey($key) || ($langsys = ($this->langsys)()) === null) {
            return parent::has($key, $locale, $fallback);
        }

        return $langsys->client()->resolveLegacyKey($key) !== null;
    }

    /**
     * What `@lang` and `@t` print, raw as Laravel prints `@lang`, but safe (FRM-8). The SDK renders
     * the line: a catalog translation is rebuilt from the source's own elements around the
     * translated runs, each run text, so a translator can place the source's tags but never add one
     * or inject script; the app's own lang-file line is printed as Laravel prints it.
     */
    public function getHtml($key, array $replace = [], $locale = null): string
    {
        if (!is_string($key) || $key === '' || self::_isValidationKey($key) || ($langsys = ($this->langsys)()) === null) {
            return (string) parent::get($key, $replace, $locale);
        }

        return $this->_resolve($langsys, $key, $replace, $locale, '__', null, rich: true)['text'];
    }

    /**
     * FRM-3's middle step, called by the SDK on a catalog miss: this call's line in the app's own
     * lang files for the locale being rendered, unfilled and converted as the source is, or null.
     * Laravel's fallback locale is not consulted — a miss there is the SDK's to answer with the
     * source.
     */
    public function fallbackLine(string $phrase, string $locale, mixed $argument): ?string
    {
        [$argument, $replace, $entryPoint, $count] = $this->call ?? [$argument, [], '__', null];

        if (!is_string($argument) || $argument === '') {
            return null;
        }

        foreach (LaravelLocales::candidates($locale, $this->locale) as $candidate) {
            $line = parent::get($argument, [], $candidate, false);

            if (is_string($line) && $line !== $argument) {
                return LegacyValue::fromCall($line, $replace, $entryPoint, $count)['text'];
            }
        }

        return null;
    }

    /**
     * A key a file holds is the SDK's to resolve and convert; any other argument is literal source
     * text (MIG-2), written in the syntax of the Laravel call that received it, and the SDK reads
     * a literal as Langsys syntax — so it is converted here, by the core's own table for that entry
     * point, and handed over already converted. The same rule decides what `langsys:sync`
     * registers, so the runtime looks up exactly what sync registered.
     *
     * @return array{text: string, from: string}
     */
    private function _resolve(LangsysTranslator $langsys, string $key, array $replace, ?string $locale, string $entryPoint, int|float|null $count, bool $rich = false): array
    {
        $client = $langsys->client();

        // FRM-4: a page its own browser SDK translates gets the source, in the language the source
        // is written in, and that SDK renders the translation.
        if (ResponseKind::current() === ResponseKind::CLIENT) {
            $locale = $this->getFallback();
        }

        $locale ??= $this->locale;
        $legacy = $client->resolveLegacyKey($key);

        if ($legacy !== null) {
            [$phrase, $category, $params] = [$legacy['phrase'], $legacy['category'], $entryPoint === 'trans_choice' ? $replace + ['count' => $count] : $replace];
            $argument = $key;
        } else {
            $call = LegacyValue::fromCall($key, $replace, $entryPoint, $count);

            if (!$call['recognised']) {
                $client->getLogger()->warning('A translation call\'s text is registered as written: it ' . $call['issue'], ['text' => $key]);
            }

            [$phrase, $category, $params, $argument] = [$call['text'], null, $call['params'], $call['text']];
        }

        $this->call = [$key, $replace, $entryPoint, $count];

        try {
            if ($rich) {
                $answer = $langsys->translateRich($phrase, $category, $params, $locale);

                return ['text' => $answer['html'], 'from' => $answer['from']];
            }

            return $langsys->resolve($legacy !== null ? $argument : $phrase, null, $params, $locale);
        } finally {
            $this->call = null;
        }
    }

    /** Validation lines are the server-messages lane's: Laravel's validator reads them through here. */
    private static function _isValidationKey(mixed $key): bool
    {
        return is_string($key) && ($key === 'validation' || str_starts_with($key, 'validation.'));
    }
}
