<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\InvokableValidationRule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;
use Langsys\Laravel\Translation\CatalogTranslator;
use Langsys\SDK\Messages\HasMessageTemplate;
use Langsys\SDK\Messages\MessageTemplate;
use Langsys\SDK\Messages\RuleTemplate;
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
     * @param  array<string, list<string>>|null  $ruleMessages  The bag as the rules left it, before any `after()` hook ran; null when none did.
     * @return list<ServerMessage>
     */
    public static function fromValidator(Validator $validator, ?string $sourceLocale = null, ?array $ruleMessages = null): array
    {
        $sourceLocale = $sourceLocale ?? config('app.fallback_locale');
        $translator = $validator->getTranslator();

        if (!$translator instanceof CatalogTranslator) {
            return self::_fromValidator($validator, $sourceLocale, $ruleMessages);
        }

        // A message, label or option word the app read through `__()` is in the request's language
        // by the time the validator holds it: the entries are written from its source, as the
        // listing reads it. What Laravel produced is matched against them as they were.
        $produced = [$validator->customMessages, $validator->customAttributes, $validator->customValues];
        $source = fn (array $declared) => self::_sourceOf($translator, $declared, $sourceLocale);
        [$validator->customMessages, $validator->customAttributes, $validator->customValues] = array_map($source, $produced);
        self::$produced[] = $produced;

        try {
            // Every read here is a probe of what Laravel already told the app's missing-key handler of.
            return $translator->quietly(fn () => self::_fromValidator($validator, $sourceLocale, $ruleMessages));
        } finally {
            array_pop(self::$produced);
            [$validator->customMessages, $validator->customAttributes, $validator->customValues] = $produced;
        }
    }

    /**
     * The app's messages, labels and option words as Laravel produced them, while the entries are
     * built from their source: what a produced message is matched against.
     *
     * @var list<array{0: array, 1: array, 2: array}>
     */
    private static array $produced = [];

    /** Each string `__()` returned this request, read again in the source language. */
    private static function _sourceOf(CatalogTranslator $translator, array $declared, string $sourceLocale): array
    {
        foreach ($declared as $name => $value) {
            $declared[$name] = is_array($value) ? self::_sourceOf($translator, $value, $sourceLocale) : (is_string($value) ? $translator->sourceOf($value, $sourceLocale) ?? $value : $value);
        }

        return $declared;
    }

    /**
     * Reads what Laravel produced from: the validator holding the app's messages, labels and option
     * words as Laravel held them when it failed.
     *
     * @template T
     * @param  \Closure(): T  $read
     * @return T
     */
    private static function _asProduced(Validator $validator, \Closure $read): mixed
    {
        $produced = end(self::$produced);

        if ($produced === false) {
            return $read();
        }

        $source = [$validator->customMessages, $validator->customAttributes, $validator->customValues];
        [$validator->customMessages, $validator->customAttributes, $validator->customValues] = $produced;

        try {
            return $read();
        } finally {
            [$validator->customMessages, $validator->customAttributes, $validator->customValues] = $source;
        }
    }

    /** @return list<ServerMessage> */
    private static function _fromValidator(Validator $validator, string $sourceLocale, ?array $ruleMessages): array
    {
        $failed = $validator->failed();
        $entries = [];

        foreach ($failed as $attribute => $rules) {
            $all = $validator->errors()->get((string) $attribute);
            $messages = $ruleMessages === null ? $all : ($ruleMessages[$attribute] ?? []);
            $spans = self::_messageSpans(array_keys($rules), count($messages));
            $cursor = 0;

            foreach ($rules as $rule => $parameters) {
                $span = array_slice($messages, $cursor, $spans[$rule]);
                $cursor += $spans[$rule];
                array_push($entries, ...self::_entries($validator, (string) $attribute, (string) $rule, (array) $parameters, $sourceLocale, $span));
            }

            // A hook's message on a field its rules failed follows theirs, and is text (MSG-9).
            foreach (array_slice($all, count($messages)) as $message) {
                $entries[] = ServerMessage::fromText(self::_textInSource($validator, (string) $message, $sourceLocale), (string) $attribute);
            }
        }

        // A failure carrying text and no rule — ValidationException::withMessages(), or a package
        // adding straight to the bag. Its text is all there is, so it becomes the template (MSG-9).
        foreach ($validator->errors()->messages() as $attribute => $messages) {
            if (isset($failed[$attribute])) {
                continue;
            }

            foreach ($messages as $message) {
                $entries[] = ServerMessage::fromText(self::_textInSource($validator, (string) $message, $sourceLocale), (string) $attribute);
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

        // FRM-2: a rule object that states its template is sent from it, as the listing lists it.
        $object = self::_ruleObject($validator, $attribute, $rule);

        if ($object instanceof HasMessageTemplate) {
            $label = self::_inSourceLocale($validator, $sourceLocale, fn () => $validator->getDisplayableAttribute($attribute));
            $stated = RuleTemplate::forField($object, $label);

            return [ServerMessage::make(self::_code($rule), $stated['template'], $stated['params'], $attribute)];
        }

        // Laravel's `Password` prints its own lines: each message is the entry the listing lists
        // for the line that printed it, found by filling each in the request's language.
        if ($object instanceof Password && method_exists($object, 'appliedRules')) {
            $printed = self::_asProduced($validator, fn () => self::forPassword($validator, $attribute, $object, $validator->getTranslator()->getLocale()));
            $listed = self::forPassword($validator, $attribute, $object, $sourceLocale);

            return array_map(function (string $text) use ($validator, $attribute, $rule, $sourceLocale, $printed, $listed) {
                foreach ($printed as $i => $entry) {
                    if (MessageTemplate::fill($entry->getTemplate(), $entry->getParams()) === $text) {
                        return $listed[$i];
                    }
                }

                return ServerMessage::make(self::_code($rule), self::_lineInSource($validator, $attribute, $rule, self::_textInSource($validator, $text, $sourceLocale), $sourceLocale), [], $attribute);
            }, $span);
        }

        // Any other rule object, or a closure: the text it produced is the only source there is,
        // one entry per `$fail()`, and the listing reports it so it can be given a template. Messages
        // that do not line up one for one with what it produced, such as ones keyed to other fields,
        // leave the produced text.
        $texts = self::_messagesInSource($validator, $attribute, $rule, $object, $sourceLocale);
        $texts = $texts !== null && count($texts) === count($span) ? $texts : array_map(fn (string $text) => self::_textInSource($validator, $text, $sourceLocale), $span);

        return array_map(fn (string $text) => ServerMessage::make(self::_code($rule), self::_lineInSource($validator, $attribute, $rule, $text, $sourceLocale), [], $attribute), $texts);
    }

    /** Text a rule or hook produced, from its source where `__()` returned it this request. */
    private static function _textInSource(Validator $validator, string $text, string $sourceLocale): string
    {
        $translator = $validator->getTranslator();

        return $translator instanceof CatalogTranslator ? $translator->sourceOf($text, $sourceLocale) ?? $text : $text;
    }

    /**
     * An app's rule object with Laravel's `message()` states its message again in the source
     * language, filled as Laravel fills it, which is the text the listing lists: a message the app
     * reads through `__()` is in the request's language as produced. Null where the produced text
     * is all there is: a `$fail()`, or a message the app declared for the rule, which Laravel
     * picks over `message()`.
     *
     * @return ?list<string>
     */
    private static function _messagesInSource(Validator $validator, string $attribute, string $rule, ?object $object, ?string $sourceLocale): ?array
    {
        if ($object === null || str_starts_with($rule, 'Illuminate\\') || !method_exists($object, 'message') || self::declaredFor($validator, $attribute, $rule) !== null) {
            return null;
        }

        return array_values(self::_inSourceLocale($validator, $sourceLocale, fn () => array_map(fn (mixed $message) => $validator->makeReplacements((string) $message, $attribute, $rule, []), (array) $object->message())));
    }

    /**
     * The entries Laravel's `Password` can send for a field, each built as a failing request builds
     * it: its nested rules' (`string`, `min`, `max`, and any given as text) with their values as
     * markers, a rule object it was given from its own template or message, then one per
     * requirement it checks, Laravel's `validation.password.*` line with the label written in. `Password` states its messages only once it has failed, and failing it could
     * ask a service whether the password has leaked, so its rules are read, never run. The code is
     * the class Laravel records the failure under.
     *
     * @return list<ServerMessage>
     */
    public static function forPassword(Validator $validator, string $attribute, Password $rule, ?string $sourceLocale = null): array
    {
        $applied = $rule->appliedRules();
        $entries = [];

        foreach (['string', 'min:' . $applied['min'], ...($applied['max'] ? ['max:' . $applied['max']] : []), ...array_filter((array) $applied['customRules'], 'is_string')] as $nested) {
            [$name, $parameters] = ValidationRuleParser::parse($nested);
            $entry = self::forRule($validator, $attribute, $name, $parameters, $sourceLocale);

            if ($entry !== null) {
                $entries[] = ServerMessage::make(Password::class, $entry->getTemplate(), $entry->getParams(), $attribute);
            }
        }

        return self::_inSourceLocale($validator, $sourceLocale ?? config('app.fallback_locale'), function () use ($validator, $attribute, $applied, $entries) {
            // A rule object it was given states its own message: its template, or its `message()`
            // filled as Laravel fills it.
            foreach ((array) $applied['customRules'] as $nested) {
                if ($nested instanceof HasMessageTemplate) {
                    $stated = RuleTemplate::forField($nested, $validator->getDisplayableAttribute($attribute));
                    $entries[] = ServerMessage::make(Password::class, $stated['template'], $stated['params'], $attribute);
                } elseif (is_object($nested) && method_exists($nested, 'message')) {
                    foreach (array_filter((array) $nested->message(), 'is_string') as $message) {
                        $entries[] = ServerMessage::make(Password::class, $validator->makeReplacements($message, $attribute, get_class($nested), []), [], $attribute);
                    }
                }
            }

            foreach (['mixedCase' => 'mixed', 'letters' => 'letters', 'symbols' => 'symbols', 'numbers' => 'numbers', 'uncompromised' => 'uncompromised'] as $requirement => $line) {
                if ($applied[$requirement]) {
                    $entries[] = ServerMessage::make(Password::class, $validator->makeReplacements((string) self::_call($validator, 'getMessage', [$attribute, "password.$line"]), $attribute, "password.$line", []), [], $attribute);
                }
            }

            return $entries;
        });
    }

    /** The message the app declared for a rule object on a field (`'code.' . Rule::class`), which Laravel picks over the rule's own. */
    public static function declaredFor(Validator $validator, string $attribute, string $rule): ?string
    {
        $message = self::_call($validator, 'getFromLocalArray', [$attribute, $rule]);

        return is_string($message) ? $message : null;
    }

    /**
     * A rule object of Laravel's own (`Rules\Enum`, `Rules\Password`) prints one of Laravel's
     * validation lines, filled in the request's language: the line that fills to its text is
     * written in the source language instead, as the listing lists it. Text no line fills to is
     * sent as it is.
     */
    private static function _lineInSource(Validator $validator, string $attribute, string $rule, string $text, ?string $sourceLocale): string
    {
        if (!str_starts_with($rule, 'Illuminate\\')) {
            return $text;
        }

        $translator = $validator->getTranslator();

        foreach (Arr::dot((array) $translator->get('validation')) as $key => $line) {
            if (is_string($line) && self::_asProduced($validator, fn () => $validator->makeReplacements($line, $attribute, $rule, [])) === $text) {
                return self::_inSourceLocale($validator, $sourceLocale, fn () => $validator->makeReplacements((string) $translator->get("validation.$key"), $attribute, $rule, []));
            }
        }

        return $text;
    }

    /**
     * The rule object Laravel recorded a failure under, by its class: `failed()` keeps only the
     * class, and the instance holds the values its template's markers name.
     */
    private static function _ruleObject(Validator $validator, string $attribute, string $rule): ?object
    {
        foreach ($validator->getRules()[$attribute] ?? [] as $candidate) {
            $candidate = $candidate instanceof InvokableValidationRule ? $candidate->invokable() : $candidate;

            if (is_object($candidate) && get_class($candidate) === $rule) {
                return $candidate;
            }
        }

        return null;
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

        // The line, its labels and the option words written in are all the source language's: the
        // request's are the message bag's, and the template is what every locale translates from.
        return self::_inSourceLocale($validator, $sourceLocale ?? config('app.fallback_locale'), function () use ($validator, $attribute, $rule, $parameters, $placeholders, $code) {
            $line = (string) self::_call($validator, 'getMessage', [$attribute, $rule]);
            [$line, $params] = self::_markMarkers($validator, $line, $attribute, $rule, $parameters, $placeholders);

            return ServerMessage::make($code, $validator->makeReplacements($line, $attribute, $rule, $parameters), $params, $attribute);
        });
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
     * What Laravel's validator reads through its translator — the line for a failure, a custom
     * message when the application declared one, a field's label, an option's word — read in the
     * language the templates are written in, not in the language of the request.
     *
     * @template T
     * @param  \Closure(): T  $read
     * @return T
     */
    private static function _inSourceLocale(Validator $validator, ?string $sourceLocale, \Closure $read): mixed
    {
        $translator = $validator->getTranslator();
        $previous = $translator->getLocale();

        if ($sourceLocale !== null) {
            $translator->setLocale($sourceLocale);
        }

        try {
            return $read();
        } finally {
            $translator->setLocale($previous);
        }
    }

    /**
     * Replaces the placeholders that hold a non-translatable value with `{name}` markers, and takes
     * each value from Laravel by asking its replacer for that placeholder alone. Besides each
     * rule's own, Laravel fills a few in any message — the user's input and a list item's index,
     * position and ordinal position (`Line #:position`, `:second-index`, `:ordinal-position`) —
     * which are values in every template.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function _markMarkers(Validator $validator, string $line, string $attribute, string $rule, array $parameters, array $placeholders): array
    {
        $params = [];

        foreach (['input', 'index', 'position', 'ordinal-position'] as $generic) {
            $placeholders[$generic] ??= RuleWording::MARKER;
        }

        foreach (['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth'] as $nth) {
            $placeholders["$nth-index"] ??= RuleWording::MARKER;
            $placeholders["$nth-position"] ??= RuleWording::MARKER;
            $placeholders["$nth-ordinal-position"] ??= RuleWording::MARKER;
        }

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

            // An operand Laravel writes in as another field's label stays in the sentence: a label
            // is translatable. Anything else it fills — a date, a number, a size — is a value.
            if ($kind === RuleWording::OPERAND && self::_operandIsALabel($validator, $rule, (string) ($parameters[0] ?? ''))) {
                continue;
            }

            // A marker's name is a word: Laravel's `:second-index` is `{second_index}`.
            $marker = str_replace('-', '_', $placeholder);
            $line = self::_markSpellings($validator, $line, $placeholder, '{' . $marker . '}', $attribute, $rule, $parameters);

            // MSG-4: a number travels as a number.
            $params[$marker] = is_numeric($filled) ? $filled + 0 : $filled;
        }

        return [$line, $params];
    }

    /**
     * Each spelling of a placeholder in the line that Laravel's replacer fills, made the marker:
     * Laravel reads a list item's `:index` and `:position` whatever their case, and every other
     * placeholder as written, so `:INDEX` is a value while `:MIN` stays in the sentence as Laravel
     * prints it.
     */
    private static function _markSpellings(Validator $validator, string $line, string $placeholder, string $marker, string $attribute, string $rule, array $parameters): string
    {
        $marked = '';
        $at = 0;

        while (($found = stripos($line, ':' . $placeholder, $at)) !== false) {
            $spelling = substr($line, $found, strlen($placeholder) + 1);
            $marked .= substr($line, $at, $found - $at) . ($validator->makeReplacements($spelling, $attribute, $rule, $parameters) === $spelling ? $spelling : $marker);
            $at = $found + strlen($spelling);
        }

        return $marked . substr($line, $at);
    }

    /**
     * Whether Laravel's replacer writes an operand in as another field's label, decided as it
     * decides: a date rule's operand when it is no date (`before:ends_at`), a size rule's when it
     * names a field holding no value. A field holding one is compared by its size, a number.
     */
    private static function _operandIsALabel(Validator $validator, string $rule, string $parameter): bool
    {
        if ($parameter === '') {
            return false;
        }

        return in_array(self::_code($rule), ['gt', 'gte', 'lt', 'lte'], true)
            ? !is_numeric($parameter) && Arr::get($validator->getData(), $parameter) === null
            : strtotime($parameter) === false;
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
