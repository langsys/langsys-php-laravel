<?php

namespace Langsys\Laravel\Translation;

use Closure;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Translation\Translator;
use Langsys\Laravel\Http\ResponseKind;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Support\ClientState;
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
    /**
     * The request attribute holding the lines `__()` returned most recently this request, each with
     * the locale its text is in, or null for the source's, so a JSON response can name the language
     * of a `message` it carries (FRM-5).
     */
    public const WRITTEN = 'langsys.written';

    /** How many of the latest lines it keeps: a JSON body's `message` is written just before it. */
    private const WRITTEN_KEPT = 64;

    /** The call being resolved, for the miss fallback: [argument, replacements, entry point, count, Laravel's locale for it]. */
    private ?array $call = null;

    /** Whether Laravel's own `choice()` is running for a miss, whose reads are of Laravel's files alone. */
    private bool $asLaravel = false;

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
        if ($this->asLaravel || !is_string($key) || $key === '' || self::_isValidationKey($key) || ($langsys = ($this->langsys)()) === null) {
            return parent::get($key, $replace, $locale, $fallback);
        }

        // A key holding a list of lines (`__('enums.status')`) answers the list, as Laravel's does,
        // each line through the catalog: the catalog holds lines, not lists.
        if ($langsys->client()->resolveLegacyKey($key) === null && is_array($lines = $this->_quietly(fn () => parent::get($key, [], $locale, $fallback)))) {
            return $this->_lines($key, $lines, $replace, $locale);
        }

        return $this->_resolve($langsys, $key, $replace, $locale, '__', null)['text'];
    }

    private function _lines(string $key, array $lines, array $replace, ?string $locale): array
    {
        foreach ($lines as $name => $line) {
            $lines[$name] = is_array($line) ? $this->_lines("$key.$name", $line, $replace, $locale) : $this->get("$key.$name", $replace, $locale);
        }

        return $lines;
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

        // What Laravel's own files hold, a list included, read as Laravel's has() reads them: the
        // locale and fallback asked for, with the app's missing-key handler off.
        $locale = $locale ?: $this->locale;
        $line = $this->_quietly(fn () => parent::get($key, [], $locale, $fallback));

        return !is_null($this->loaded['*']['*'][$locale][$key] ?? null) || $line !== $key;
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

    /** What `@choice` prints: `choice()`, raw as Laravel prints `@choice`, and safe as `getHtml()` is (FRM-8). */
    public function choiceHtml($key, $number, array $replace = [], $locale = null): string
    {
        if (!is_string($key) || $key === '' || self::_isValidationKey($key) || ($langsys = ($this->langsys)()) === null) {
            return (string) parent::choice($key, $number, $replace, $locale);
        }

        if (is_countable($number)) {
            $number = count($number);
        }

        return $this->_resolve($langsys, $key, $replace, $locale, 'trans_choice', $number, rich: true)['text'];
    }

    /**
     * FRM-3's middle step, called by the SDK on a catalog miss: this call's line in the app's own
     * lang files, unfilled and converted as the source is, or null. The files are read as Laravel
     * reads them for the call: in its locale exactly as set, not the one the catalog is served in,
     * so an app that sets `ja`, which the project does not serve, is served the base catalog and
     * prints its own `ja` line on a miss, and one that sets `es-MX` reads no `es` file, exactly as
     * without Langsys (FRM-1). Laravel's fallback locale is not consulted — a miss there is the
     * SDK's to answer with the source. A miss outside a Laravel call, such as the facade's, has no
     * locale of Laravel's, so the spellings Laravel could use for the locale served are read.
     */
    public function fallbackLine(string $phrase, string $locale, mixed $argument): ?string
    {
        [$argument, $replace, $entryPoint, $count, $laravel] = $this->call ?? [$argument, [], '__', null, null];

        if (!is_string($argument) || $argument === '') {
            return null;
        }

        foreach ($laravel !== null ? [$laravel] : LaravelLocales::candidates($locale, $this->locale) as $candidate) {
            $line = $this->_quietly(fn () => parent::get($argument, [], $candidate, false));

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
        // An empty locale is none, as in Laravel's own translator: the call is in the current one.
        $locale = $locale ?: null;

        // A value prints as Laravel's own translator prints it: an object through Laravel's own
        // conversion — an enum's value, the app's `Lang::stringable()` handler — and a closure wraps
        // the text its tag encloses, in the line returned.
        $tags = array_filter($replace, fn (mixed $value) => $value instanceof Closure);
        $values = array_map(fn (mixed $value) => is_object($value) ? $this->makeReplacements(':value', ['value' => $value]) : $value, array_diff_key($replace, $tags));

        // FRM-4: a page its own browser SDK translates gets the source, in the language the source
        // is written in, and that SDK renders the translation.
        if (ResponseKind::current() === ResponseKind::CLIENT) {
            $locale = $this->getFallback();
        }

        $legacy = $client->resolveLegacyKey($key);

        if ($legacy !== null) {
            [$phrase, $category, $params] = [$legacy['phrase'], $legacy['category'], $entryPoint === 'trans_choice' ? $values + ['count' => $count] : $values];
            $argument = $key;
        } else {
            $call = LegacyValue::fromCall($key, $values, $entryPoint, $count);

            if (!$call['recognised']) {
                $client->getLogger()->warning('A translation call\'s text is registered as written: it ' . $call['issue'], ['text' => $key]);
            }

            [$phrase, $category, $params, $argument] = [$call['text'], null, $call['params'], $call['text']];
        }

        $this->call = [$key, $values, $entryPoint, $count, $locale ?? $this->locale];

        try {
            $answer = $rich
                ? $langsys->translateRich($phrase, $category, $params, $locale)
                : $langsys->resolve($legacy !== null ? $argument : $phrase, null, $params, $locale);
        } finally {
            $this->call = null;
        }

        $answer = ['text' => $answer['html'] ?? $answer['text'], 'from' => $answer['from']];

        // A key the base-language files hold answers with its line, as Laravel's fallback locale
        // would; only a literal nothing answered is a miss to Laravel.
        if ($legacy === null) {
            $answer = $this->_missed($answer, $key, $replace, $locale, $entryPoint === 'trans_choice' ? $count : null);
        }

        if ($tags !== []) {
            $answer['text'] = $this->makeReplacements($answer['text'], $tags);
        }

        return $rich ? $answer : $this->_written($answer, $locale);
    }

    /**
     * Laravel tells the app's missing-key handler (`Lang::handleMissingKeysUsing()`) of a line
     * nothing answered, once, and prints the line it hands back: so does a call the catalog and the
     * lang files leave to the source. Laravel's own `get()` or `choice()` tells it — which locale
     * and which values the handler hears, and the locale a choice's form is picked in, differ
     * across Laravel's versions — and what that prints is the line. A handler that hands back
     * nothing leaves the source.
     *
     * @param  array{text: string, from: string}  $answer
     * @return array{text: string, from: string}
     */
    private function _missed(array $answer, string $key, array $replace, ?string $locale, int|float|null $count): array
    {
        if ($answer['from'] !== 'source' || !isset($this->missingTranslationKeyCallback)) {
            return $answer;
        }

        $laravel = function () use ($key, $replace, $locale, $count) {
            $this->asLaravel = true;

            try {
                return $count === null ? parent::get($key, $replace, $locale) : parent::choice($key, $count, $replace, $locale);
            } finally {
                $this->asLaravel = false;
            }
        };
        $line = $laravel();

        return $line === $this->_quietly($laravel) ? $answer : ['text' => $line, 'from' => 'fallback'];
    }

    /** A read of Laravel's own files that the app's missing-key handler does not hear of: a probe is not a miss. */
    private function _quietly(Closure $read): mixed
    {
        if (!property_exists($this, 'handleMissingTranslationKeys')) {
            return $read();
        }

        $handling = $this->handleMissingTranslationKeys;
        $this->handleMissingTranslationKeys = false;

        try {
            return $read();
        } finally {
            $this->handleMissingTranslationKeys = $handling;
        }
    }

    /**
     * @param  array{text: string, from: string}  $answer
     * @return array{text: string, from: string}
     */
    private function _written(array $answer, ?string $locale): array
    {
        if (app()->bound('request')) {
            $attributes = app('request')->attributes;
            $written = (array) $attributes->get(self::WRITTEN, []);
            $written[$answer['text']] = match ($answer['from']) {
                'catalog'  => ClientState::locale($locale),
                'fallback' => $locale ?? $this->locale,
                default    => null,
            };
            $attributes->set(self::WRITTEN, array_slice($written, -self::WRITTEN_KEPT, null, true));
        }

        return $answer;
    }

    /** Validation lines are the server-messages lane's: Laravel's validator reads them through here. */
    private static function _isValidationKey(mixed $key): bool
    {
        return is_string($key) && ($key === 'validation' || str_starts_with($key, 'validation.'));
    }
}
