<?php

namespace Langsys\Laravel\Messages;

/**
 * What each placeholder in Laravel's own English line for each validation rule stands for (MSG-3,
 * MSG-11). An entry's code is Laravel's own rule name (MSG-2), so it needs no table.
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

    /** rule => placeholder => what it stands for. */
    private const RULES = [
        'accepted'               => ['attribute' => self::LABEL],
        'accepted_if'            => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'active_url'             => ['attribute' => self::LABEL],
        'after'                  => ['attribute' => self::LABEL, 'date' => self::OPERAND],
        'after_or_equal'         => ['attribute' => self::LABEL, 'date' => self::OPERAND],
        'alpha'                  => ['attribute' => self::LABEL],
        'alpha_dash'             => ['attribute' => self::LABEL],
        'alpha_num'              => ['attribute' => self::LABEL],
        'any_of'                 => ['attribute' => self::LABEL],
        'array'                  => ['attribute' => self::LABEL],
        'ascii'                  => ['attribute' => self::LABEL],
        'before'                 => ['attribute' => self::LABEL, 'date' => self::OPERAND],
        'before_or_equal'        => ['attribute' => self::LABEL, 'date' => self::OPERAND],
        'between'                => ['attribute' => self::LABEL, 'min' => self::MARKER, 'max' => self::MARKER],
        'boolean'                => ['attribute' => self::LABEL],
        'can'                    => ['attribute' => self::LABEL],
        'confirmed'              => ['attribute' => self::LABEL],
        'contains'               => ['attribute' => self::LABEL],
        'current_password'       => [],
        'date'                   => ['attribute' => self::LABEL],
        'date_equals'            => ['attribute' => self::LABEL, 'date' => self::OPERAND],
        'date_format'            => ['attribute' => self::LABEL, 'format' => self::MARKER],
        'decimal'                => ['attribute' => self::LABEL, 'decimal' => self::MARKER],
        'declined'               => ['attribute' => self::LABEL],
        'declined_if'            => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'different'              => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'digits'                 => ['attribute' => self::LABEL, 'digits' => self::MARKER],
        'digits_between'         => ['attribute' => self::LABEL, 'min' => self::MARKER, 'max' => self::MARKER],
        'dimensions'             => ['attribute' => self::LABEL],
        'distinct'               => ['attribute' => self::LABEL],
        'doesnt_contain'         => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'doesnt_end_with'        => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'doesnt_start_with'      => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'email'                  => ['attribute' => self::LABEL],
        'encoding'               => ['attribute' => self::LABEL, 'encoding' => self::MARKER],
        'ends_with'              => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'enum'                   => ['attribute' => self::LABEL],
        'exists'                 => ['attribute' => self::LABEL],
        'extensions'             => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'file'                   => ['attribute' => self::LABEL],
        'filled'                 => ['attribute' => self::LABEL],
        'gt'                     => ['attribute' => self::LABEL, 'value' => self::OPERAND],
        'gte'                    => ['attribute' => self::LABEL, 'value' => self::OPERAND],
        'hex_color'              => ['attribute' => self::LABEL],
        'image'                  => ['attribute' => self::LABEL],
        'in'                     => ['attribute' => self::LABEL],
        'in_array'               => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'in_array_keys'          => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'integer'                => ['attribute' => self::LABEL],
        'ip'                     => ['attribute' => self::LABEL],
        'ipv4'                   => ['attribute' => self::LABEL],
        'ipv6'                   => ['attribute' => self::LABEL],
        'json'                   => ['attribute' => self::LABEL],
        'list'                   => ['attribute' => self::LABEL],
        'lowercase'              => ['attribute' => self::LABEL],
        'lt'                     => ['attribute' => self::LABEL, 'value' => self::OPERAND],
        'lte'                    => ['attribute' => self::LABEL, 'value' => self::OPERAND],
        'mac_address'            => ['attribute' => self::LABEL],
        'max'                    => ['attribute' => self::LABEL, 'max' => self::MARKER],
        'max_digits'             => ['attribute' => self::LABEL, 'max' => self::MARKER],
        'mimes'                  => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'mimetypes'              => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'min'                    => ['attribute' => self::LABEL, 'min' => self::MARKER],
        'min_digits'             => ['attribute' => self::LABEL, 'min' => self::MARKER],
        'missing'                => ['attribute' => self::LABEL],
        'missing_if'             => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'missing_unless'         => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'missing_with'           => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'missing_with_all'       => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'multiple_of'            => ['attribute' => self::LABEL, 'value' => self::MARKER],
        'not_in'                 => ['attribute' => self::LABEL],
        'not_regex'              => ['attribute' => self::LABEL],
        'numeric'                => ['attribute' => self::LABEL],
        'password'               => ['attribute' => self::LABEL],
        'present'                => ['attribute' => self::LABEL],
        'present_if'             => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'present_unless'         => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'present_with'           => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'present_with_all'       => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'prohibited'             => ['attribute' => self::LABEL],
        'prohibited_if'          => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'prohibited_if_accepted' => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'prohibited_if_declined' => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'prohibited_unless'      => ['attribute' => self::LABEL, 'other' => self::LABEL, 'values' => self::VALUES],
        'prohibits'              => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'regex'                  => ['attribute' => self::LABEL],
        'required'               => ['attribute' => self::LABEL],
        'required_array_keys'    => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'required_if'            => ['attribute' => self::LABEL, 'other' => self::LABEL, 'value' => self::VALUE],
        'required_if_accepted'   => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'required_if_declined'   => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'required_unless'        => ['attribute' => self::LABEL, 'other' => self::LABEL, 'values' => self::VALUES],
        'required_with'          => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'required_with_all'      => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'required_without'       => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'required_without_all'   => ['attribute' => self::LABEL, 'values' => self::LABELS],
        'same'                   => ['attribute' => self::LABEL, 'other' => self::LABEL],
        'size'                   => ['attribute' => self::LABEL, 'size' => self::MARKER],
        'starts_with'            => ['attribute' => self::LABEL, 'values' => self::MARKER],
        'string'                 => ['attribute' => self::LABEL],
        'timezone'               => ['attribute' => self::LABEL],
        'unique'                 => ['attribute' => self::LABEL],
        'uploaded'               => ['attribute' => self::LABEL],
        'uppercase'              => ['attribute' => self::LABEL],
        'url'                    => ['attribute' => self::LABEL],
        'ulid'                   => ['attribute' => self::LABEL],
        'uuid'                   => ['attribute' => self::LABEL],
    ];

    /**
     * @return array<string, string>|null  placeholder => kind; null for a rule Laravel ships no line for.
     */
    public static function placeholders(string $rule): ?array
    {
        return self::RULES[$rule] ?? null;
    }
}
