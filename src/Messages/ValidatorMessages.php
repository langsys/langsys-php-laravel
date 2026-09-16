<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Langsys\SDK\Messages\MessageCodes;
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
            foreach ($rules as $rule => $parameters) {
                $entries[] = self::_entry($validator, (string) $attribute, (string) $rule, (array) $parameters, $sourceLocale);
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

    private static function _entry(Validator $validator, string $attribute, string $rule, array $parameters, ?string $sourceLocale): ServerMessage
    {
        $classification = RuleWording::classification(Str::snake($rule));

        // A rule this package has no wording for: an application's own rule object, or a closure.
        // Whatever text it produced is the only source there is, and the build-time command
        // reports it so it can be given a real template (MSG-7).
        if ($classification === null) {
            return ServerMessage::fromText((string) $validator->errors()->first($attribute), $attribute);
        }

        $line = self::_sourceLine($validator, $attribute, $rule, $sourceLocale);
        [$line, $params] = self::_markMarkers($validator, $line, $attribute, $rule, $parameters, $classification['placeholders']);

        return ServerMessage::make(
            self::_code($validator, $classification['code'], $attribute, $parameters),
            $validator->makeReplacements($line, $attribute, $rule, $parameters),
            $params,
            $attribute
        );
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
     * @param  string|array  $code  A vocabulary slug, or a bound whose code follows the field's type.
     */
    private static function _code(Validator $validator, $code, string $attribute, array $parameters): string
    {
        if (is_string($code)) {
            return $code;
        }

        if (isset($code['variants'])) {
            return (string) reset($code['variants']);
        }

        $type = $code['as'] ?? self::_call($validator, 'getAttributeType', [$attribute]);
        $side = $code['bound'] === RuleWording::EITHER
            ? self::_side($validator, $attribute, $parameters)
            : $code['bound'];

        return (string) MessageCodes::forBound($side, $type);
    }

    /**
     * `between` and `size` do not say which side failed, so it is read off the value — structure,
     * never the rendered text.
     */
    private static function _side(Validator $validator, string $attribute, array $parameters): string
    {
        $size = self::_call($validator, 'getSize', [$attribute, $validator->getValue($attribute)]);

        return $size < (float) ($parameters[0] ?? 0) ? RuleWording::LOWER : RuleWording::UPPER;
    }

    /**
     * Laravel keeps the pieces below protected, and a normalizer has to read the same values the
     * validator itself would use: the message it picked, the field's type, and its size.
     */
    private static function _call(Validator $validator, string $method, array $arguments)
    {
        return (new ReflectionMethod($validator, $method))->invokeArgs($validator, $arguments);
    }
}
