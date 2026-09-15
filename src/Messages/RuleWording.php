<?php

namespace Langsys\Laravel\Messages;

use Langsys\SDK\Messages\MessageCodes;

/**
 * How each of Laravel's validation rules becomes a server message (MSG-2, MSG-3, MSG-11): the code
 * a client branches on, and what each placeholder in Laravel's own English line stands for.
 *
 * The wording itself is not here. It is the installed Laravel's `validation.php`, so the defaults
 * stay Laravel's defaults. This table only says, per placeholder, whether its value is translatable
 * and written into the sentence, or a value that stays out of the phrase as a `{name}` marker.
 * `tests/Messages/RuleWordingTest.php` reads that file and fails on any rule or placeholder this
 * table does not account for, so a Laravel upgrade cannot send an unclassified message.
 *
 * @internal
 */
final class RuleWording
{
    /** A field's label, written into the sentence: it governs agreement. */
    public const LABEL = 'label';

    /** Several fields' labels, written in as a list. */
    public const LABELS = 'labels';

    /** A translatable value a rule names, written in; a new one registers when first emitted (MSG-8). */
    public const VALUE = 'value';

    /** Several translatable values, written in as a list. */
    public const VALUES = 'values';

    /** A value that is not translatable — a number, a format, a literal list — kept as a `{name}` marker. */
    public const MARKER = 'marker';

    /** A literal, kept as a marker, or another field, whose label is written in. Decided per failure, as Laravel decides it. */
    public const OPERAND = 'operand';

    /** The side of a bound a size rule failed on. The code then follows the field's type. */
    public const LOWER = 'lower';
    public const UPPER = 'upper';

    /** `between` and `size`: the failure does not say which side, so the side is read off the value. */
    public const EITHER = 'either';

    /** The field types Laravel tells a size rule apart by, as `getAttributeType()` reads them. */
    private const TYPES = ['string', 'numeric', 'array', 'file'];

    /**
     * rule => [code, placeholders]. A code is a vocabulary slug, a bound whose code follows the type
     * (`as` fixes the type for digit counts), or per-variant codes for `password`.
     */
    private const RULES = [
        'accepted'               => ['required', ['attribute' => self::LABEL]],
        'accepted_if'            => ['required', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'active_url'             => ['invalid_format', ['attribute' => self::LABEL]],
        'after'                  => ['invalid_date', ['attribute' => self::LABEL, 'date' => self::OPERAND]],
        'after_or_equal'         => ['invalid_date', ['attribute' => self::LABEL, 'date' => self::OPERAND]],
        'alpha'                  => ['invalid_format', ['attribute' => self::LABEL]],
        'alpha_dash'             => ['invalid_format', ['attribute' => self::LABEL]],
        'alpha_num'              => ['invalid_format', ['attribute' => self::LABEL]],
        'any_of'                 => ['invalid', ['attribute' => self::LABEL]],
        'array'                  => ['invalid_type', ['attribute' => self::LABEL]],
        'ascii'                  => ['invalid_format', ['attribute' => self::LABEL]],
        'before'                 => ['invalid_date', ['attribute' => self::LABEL, 'date' => self::OPERAND]],
        'before_or_equal'        => ['invalid_date', ['attribute' => self::LABEL, 'date' => self::OPERAND]],
        'between'                => [['bound' => self::EITHER], ['attribute' => self::LABEL, 'min' => self::MARKER, 'max' => self::MARKER]],
        'boolean'                => ['invalid_type', ['attribute' => self::LABEL]],
        'can'                    => ['not_allowed', ['attribute' => self::LABEL]],
        'confirmed'              => ['mismatch', ['attribute' => self::LABEL]],
        'contains'               => ['required', ['attribute' => self::LABEL]],
        'current_password'       => ['mismatch', []],
        'date'                   => ['invalid_format', ['attribute' => self::LABEL]],
        'date_equals'            => ['invalid_date', ['attribute' => self::LABEL, 'date' => self::OPERAND]],
        'date_format'            => ['invalid_format', ['attribute' => self::LABEL, 'format' => self::MARKER]],
        'decimal'                => ['invalid_format', ['attribute' => self::LABEL, 'decimal' => self::MARKER]],
        'declined'               => ['not_allowed', ['attribute' => self::LABEL]],
        'declined_if'            => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'different'              => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'digits'                 => ['invalid_format', ['attribute' => self::LABEL, 'digits' => self::MARKER]],
        'digits_between'         => [['bound' => self::EITHER, 'as' => 'string'], ['attribute' => self::LABEL, 'min' => self::MARKER, 'max' => self::MARKER]],
        'dimensions'             => ['invalid_format', ['attribute' => self::LABEL]],
        'distinct'               => ['already_taken', ['attribute' => self::LABEL]],
        'doesnt_contain'         => ['not_allowed', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'doesnt_end_with'        => ['not_allowed', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'doesnt_start_with'      => ['not_allowed', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'email'                  => ['invalid_format', ['attribute' => self::LABEL]],
        'encoding'               => ['invalid_format', ['attribute' => self::LABEL, 'encoding' => self::MARKER]],
        'ends_with'              => ['invalid_format', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'enum'                   => ['invalid_option', ['attribute' => self::LABEL]],
        'exists'                 => ['not_found', ['attribute' => self::LABEL]],
        'extensions'             => ['invalid_type', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'file'                   => ['invalid_type', ['attribute' => self::LABEL]],
        'filled'                 => ['required', ['attribute' => self::LABEL]],
        'gt'                     => [['bound' => self::LOWER], ['attribute' => self::LABEL, 'value' => self::OPERAND]],
        'gte'                    => [['bound' => self::LOWER], ['attribute' => self::LABEL, 'value' => self::OPERAND]],
        'hex_color'              => ['invalid_format', ['attribute' => self::LABEL]],
        'image'                  => ['invalid_type', ['attribute' => self::LABEL]],
        'in'                     => ['invalid_option', ['attribute' => self::LABEL]],
        'in_array'               => ['invalid_option', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'in_array_keys'          => ['required', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'integer'                => ['invalid_type', ['attribute' => self::LABEL]],
        'ip'                     => ['invalid_format', ['attribute' => self::LABEL]],
        'ipv4'                   => ['invalid_format', ['attribute' => self::LABEL]],
        'ipv6'                   => ['invalid_format', ['attribute' => self::LABEL]],
        'json'                   => ['invalid_format', ['attribute' => self::LABEL]],
        'list'                   => ['invalid_type', ['attribute' => self::LABEL]],
        'lowercase'              => ['invalid_format', ['attribute' => self::LABEL]],
        'lt'                     => [['bound' => self::UPPER], ['attribute' => self::LABEL, 'value' => self::OPERAND]],
        'lte'                    => [['bound' => self::UPPER], ['attribute' => self::LABEL, 'value' => self::OPERAND]],
        'mac_address'            => ['invalid_format', ['attribute' => self::LABEL]],
        'max'                    => [['bound' => self::UPPER], ['attribute' => self::LABEL, 'max' => self::MARKER]],
        'max_digits'             => [['bound' => self::UPPER, 'as' => 'string'], ['attribute' => self::LABEL, 'max' => self::MARKER]],
        'mimes'                  => ['invalid_type', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'mimetypes'              => ['invalid_type', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'min'                    => [['bound' => self::LOWER], ['attribute' => self::LABEL, 'min' => self::MARKER]],
        'min_digits'             => [['bound' => self::LOWER, 'as' => 'string'], ['attribute' => self::LABEL, 'min' => self::MARKER]],
        'missing'                => ['not_allowed', ['attribute' => self::LABEL]],
        'missing_if'             => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'missing_unless'         => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'missing_with'           => ['not_allowed', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'missing_with_all'       => ['not_allowed', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'multiple_of'            => ['invalid', ['attribute' => self::LABEL, 'value' => self::MARKER]],
        'not_in'                 => ['not_allowed', ['attribute' => self::LABEL]],
        'not_regex'              => ['invalid_format', ['attribute' => self::LABEL]],
        'numeric'                => ['invalid_type', ['attribute' => self::LABEL]],
        'password'               => [['variants' => ['letters' => 'invalid_format', 'mixed' => 'invalid_format', 'numbers' => 'invalid_format', 'symbols' => 'invalid_format', 'uncompromised' => 'not_allowed']], ['attribute' => self::LABEL]],
        'present'                => ['required', ['attribute' => self::LABEL]],
        'present_if'             => ['required', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'present_unless'         => ['required', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'present_with'           => ['required', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'present_with_all'       => ['required', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'prohibited'             => ['not_allowed', ['attribute' => self::LABEL]],
        'prohibited_if'          => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'prohibited_if_accepted' => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'prohibited_if_declined' => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'prohibited_unless'      => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL, 'values' => self::VALUES]],
        'prohibits'              => ['not_allowed', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'regex'                  => ['invalid_format', ['attribute' => self::LABEL]],
        'required'               => ['required', ['attribute' => self::LABEL]],
        'required_array_keys'    => ['required', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'required_if'            => ['required', ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE]],
        'required_if_accepted'   => ['required', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'required_if_declined'   => ['required', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'required_unless'        => ['required', ['attribute' => self::LABEL, 'other' => self::LABEL, 'values' => self::VALUES]],
        'required_with'          => ['required', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'required_with_all'      => ['required', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'required_without'       => ['required', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'required_without_all'   => ['required', ['attribute' => self::LABEL, 'values' => self::LABELS]],
        'same'                   => ['mismatch', ['attribute' => self::LABEL, 'other' => self::LABEL]],
        'size'                   => [['bound' => self::EITHER], ['attribute' => self::LABEL, 'size' => self::MARKER]],
        'starts_with'            => ['invalid_format', ['attribute' => self::LABEL, 'values' => self::MARKER]],
        'string'                 => ['invalid_type', ['attribute' => self::LABEL]],
        'timezone'               => ['invalid_format', ['attribute' => self::LABEL]],
        'unique'                 => ['already_taken', ['attribute' => self::LABEL]],
        'uploaded'               => ['invalid', ['attribute' => self::LABEL]],
        'uppercase'              => ['invalid_format', ['attribute' => self::LABEL]],
        'url'                    => ['invalid_format', ['attribute' => self::LABEL]],
        'ulid'                   => ['invalid_format', ['attribute' => self::LABEL]],
        'uuid'                   => ['invalid_format', ['attribute' => self::LABEL]],
    ];

    /**
     * @return array{code: string|array, placeholders: array<string, string>}|null  Null for a rule Laravel ships no line for.
     */
    public static function classification(string $rule): ?array
    {
        if (!isset(self::RULES[$rule])) {
            return null;
        }

        [$code, $placeholders] = self::RULES[$rule];

        return ['code' => $code, 'placeholders' => $placeholders];
    }

    /**
     * Every code a rule can produce, across the sides and field types it can fail on.
     *
     * @return list<string>
     */
    public static function codesFor(string $rule): array
    {
        $code = self::RULES[$rule][0] ?? null;

        if ($code === null) {
            return [];
        }

        if (is_string($code)) {
            return [$code];
        }

        if (isset($code['variants'])) {
            return array_values(array_unique($code['variants']));
        }

        $sides = $code['bound'] === self::EITHER ? [self::LOWER, self::UPPER] : [$code['bound']];
        $types = isset($code['as']) ? [$code['as']] : self::TYPES;
        $codes = [];

        foreach ($sides as $side) {
            foreach ($types as $type) {
                // Which code a side and a type give is MSG-2's split, and the core owns it.
                $codes[] = MessageCodes::forBound($side, $type);
            }
        }

        return array_values(array_unique($codes));
    }
}
