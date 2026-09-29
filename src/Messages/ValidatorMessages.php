<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Langsys\SDK\Messages\ServerMessage;
use ReflectionMethod;

/**
 * Turns a failed validator into one server message per failed rule (MSG-9): built from the rule
 * and its parameters, never by reading back the string Laravel rendered.
 *
 * The sentence is Laravel's own. Rather than reproduce how Laravel writes a label, joins a list of
 * fields or tells another field from a literal date, this marks the placeholders that hold
 * non-translatable values as `{name}` first, and then lets Laravel's own replacer fill everything
 * that is left. What comes back is Laravel's sentence with the label written into it (MSG-3,
 * MSG-10) and the values outside it (MSG-11), and `ValidatorMessagesTest` pins that filling the
 * template again reproduces Laravel's message byte for byte.
 *
 * @internal
 */
final class ValidatorMessages
{
    /**
     * @param  ?string  $sourceLocale  The language the templates are written in; the app's fallback locale by default.
     * @return list<ServerMessage>
     */
    public static function fromValidator(Validator $validator, ?string $sourceLocale = null): array
    {
        $sourceLocale = $sourceLocale ?? config('app.fallback_locale');
        $failed = $validator->failed();
        $entries = [];

        foreach ($failed as $attribute => $rules) {
            $messages = $validator->errors()->get((string) $attribute);
            $spans = self::_messageSpans(array_keys($rules), count($messages));
            $cursor = 0;

            foreach ($rules as $rule => $parameters) {
                $span = array_slice($messages, $cursor, $spans[$rule]);
                $cursor += $spans[$rule];
                array_push($entries, ...self::_entries($validator, (string) $attribute, (string) $rule, (array) $parameters, $sourceLocale, $span));
            }
        }

        // A failure carrying text and no rule — ValidationException::withMessages(), or a package
        // adding straight to the bag. Its text is all there is, so it becomes the template (MSG-9).
        foreach ($validator->errors()->messages() as $attribute => $messages) {
            if (isset($failed[$attribute])) {
                continue;
            }

            foreach ($messages as $message) {
                $entries[] = ServerMessage::fromText((string) $message, (string) $attribute);
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $span  The messages this rule added to the bag.
     * @return list<ServerMessage>
     */
    private static function _entries(Validator $validator, string $attribute, string $rule, array $parameters, ?string $sourceLocale, array $span): array
    {
        $entry = self::forRule($validator, $attribute, $rule, $parameters, $sourceLocale);

        if ($entry !== null) {
            return [$entry];
        }

        // A rule Laravel ships no line for: an application's own rule object, or a closure. The
        // text it produced is the only source there is, one entry per `$fail()`, and the listing
        // command reports it so it can be given a real template (MSG-7).
        return array_map(fn (string $text) => ServerMessage::make(self::_code($rule), $text, [], $attribute), $span);
    }

    /**
     * How many of a field's messages each failed rule added. Laravel keeps them in the order the
     * rules failed; a built-in rule adds exactly one, a rule object one per `$fail()`. With one rule
     * object on the field, it owns every message beyond the built-ins; with several, where each
     * one's messages start cannot be recovered, so each owns the one at its own position.
     *
     * @param  list<string>  $rules
     * @return array<string, int>
     */
    private static function _messageSpans(array $rules, int $messages): array
    {
        $objects = array_values(array_filter($rules, fn (string $rule) => RuleWording::placeholders(self::_code($rule)) === null));
        $spans = array_fill_keys($rules, 1);

        if (count($objects) === 1) {
            $spans[$objects[0]] = max(1, $messages - (count($rules) - 1));
        }

        return $spans;
    }

    /**
     * The entry one rule produces for one field when it fails, or null for a rule Laravel ships no
     * line for. The runtime and the build-time listing both build through here, so what a failing
     * request sends is what the listing registered ahead of it.
     *
     * @param  string  $rule  As Laravel records it: `Min`, `RequiredIf`, or a rule class.
     */
    public static function forRule(Validator $validator, string $attribute, string $rule, array $parameters, ?string $sourceLocale = null): ?ServerMessage
    {
        $code = self::_code($rule);
        $placeholders = RuleWording::placeholders($code);

        if ($placeholders === null) {
            return null;
        }

        $line = self::_sourceLine($validator, $attribute, $rule, $sourceLocale ?? config('app.fallback_locale'));
        [$line, $params] = self::_markMarkers($validator, $line, $attribute, $rule, $parameters, $placeholders);

        return ServerMessage::make($code, $validator->makeReplacements($line, $attribute, $rule, $parameters), $params, $attribute);
    }

    /**
     * MSG-2: the code is Laravel's own name for the rule. A built-in rule is recorded in studly
     * case (`RequiredIf`) and is the rule as written and as `validation.php` keys it
     * (`required_if`); a rule object or a closure is recorded by its class, which is its name.
     */
    private static function _code(string $rule): string
    {
        return str_contains($rule, '\\') ? $rule : Str::snake($rule);
    }

    /**
     * Laravel's line for this failure — a custom message when the application declared one — read
     * in the language the templates are written in, not in the language of the request.
     */
    private static function _sourceLine(Validator $validator, string $attribute, string $rule, ?string $sourceLocale): string
    {
        $translator = $validator->getTranslator();
        $previous = $translator->getLocale();

        if ($sourceLocale !== null) {
            $translator->setLocale($sourceLocale);
        }

        try {
            return (string) self::_call($validator, 'getMessage', [$attribute, $rule]);
        } finally {
            $translator->setLocale($previous);
        }
    }

    /**
     * Replaces the placeholders that hold a non-translatable value with `{name}` markers, and takes
     * each value from Laravel by asking its replacer for that placeholder alone.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function _markMarkers(Validator $validator, string $line, string $attribute, string $rule, array $parameters, array $placeholders): array
    {
        $params = [];

        // Longest first: `:values` must not be matched as `:value` followed by an "s".
        uksort($placeholders, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($placeholders as $placeholder => $kind) {
            if ($kind !== RuleWording::MARKER && $kind !== RuleWording::OPERAND) {
                continue;
            }

            $filled = $validator->makeReplacements(':' . $placeholder, $attribute, $rule, $parameters);

            if ($filled === ':' . $placeholder) {
                continue;
            }

            // An operand is either another field or a literal. Asked structurally — does the
            // parameter name a field of this request? — because comparing the filled value against
            // the field's label cannot tell them apart: `before:2020-01-01` fills with the same
            // string either way. A field's label is translatable and belongs in the sentence.
            if ($kind === RuleWording::OPERAND && self::_namesAnotherField($validator, (string) ($parameters[0] ?? ''))) {
                continue;
            }

            $line = str_replace(
                [':' . $placeholder, ':' . Str::upper($placeholder), ':' . Str::ucfirst($placeholder)],
                '{' . $placeholder . '}',
                $line
            );

            // MSG-4: a number travels as a number.
            $params[$placeholder] = is_numeric($filled) ? $filled + 0 : $filled;
        }

        return [$line, $params];
    }

    private static function _namesAnotherField(Validator $validator, string $parameter): bool
    {
        return $parameter !== ''
            && (array_key_exists($parameter, $validator->getRules()) || Arr::has($validator->getData(), $parameter));
    }

    /**
     * Laravel keeps the message it picked protected, and a normalizer has to read the same line the
     * validator itself would use.
     */
    private static function _call(Validator $validator, string $method, array $arguments)
    {
        return (new ReflectionMethod($validator, $method))->invokeArgs($validator, $arguments);
    }
}
