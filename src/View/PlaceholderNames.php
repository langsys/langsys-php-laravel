<?php

namespace Langsys\Laravel\View;

/**
 * VAR-2: the name a printed value takes as a placeholder, derived from the PHP expression that
 * produced it, and ICU-safe (`[a-z][a-z0-9_]*`). The name is part of the phrase, so it follows the
 * shared table every SDK follows — the naming vectors authored by the JS core, which the tests
 * execute row for row against the shapes below.
 *
 * A shape is the vectors' own: `['identifier' => 'firstName']`, `['member' => ['user', 'name']]`,
 * `['call' => ['callee' => 'f', 'args' => [shape]]]` or `['other' => 'binary']`.
 *
 * @internal
 */
final class PlaceholderNames
{
    private const COUNTS = ['length', 'size', 'count'];

    private const UNWRAPS = ['value', 'current'];

    /**
     * The names one phrase's values take, in order. The same expression twice is one placeholder;
     * different expressions that would share a name are each prefixed with their previous segment,
     * then suffixed `_2`, `_3`; an explicit name always wins; a markup token's name is never used.
     *
     * @param  list<array{shape: array, source: string, explicit?: ?string}>  $expressions
     * @return array{names: list<string>, unnamed: list<string>} The names, and the sources that fell back to `value`
     */
    public static function names(array $expressions): array
    {
        $bySource = [];
        $derived = [];

        foreach ($expressions as $expression) {
            $source = $expression['source'];

            if (($expression['explicit'] ?? null) !== null || !array_key_exists($source, $bySource)) {
                $bySource[$source] = null;
                $derived[] = $expression;
            }
        }

        // Explicit names are fixed; a derived name shared by two expressions, or by an explicit one,
        // is prefixed on every expression that has a segment to prefix with.
        $explicit = array_map(fn ($e) => self::snake($e['explicit']), array_filter($derived, fn ($e) => ($e['explicit'] ?? null) !== null));
        $bases = [];

        foreach ($derived as $i => $expression) {
            $segments = self::_segments($expression['shape']);
            $bases[$i] = ($expression['explicit'] ?? null) !== null ? null : ($segments === null ? null : self::_base($segments));
        }

        $counts = array_count_values(array_filter($bases, 'is_string'));
        $taken = [];
        $unnamed = [];
        $fallbacks = 0;
        $assigned = [];

        foreach ($derived as $i => $expression) {
            if (($expression['explicit'] ?? null) !== null) {
                $name = self::snake($expression['explicit']);
            } elseif ($bases[$i] === null) {
                $name = ++$fallbacks === 1 ? 'value' : 'value_' . $fallbacks;
                $unnamed[] = $expression['source'];
            } else {
                $name = $bases[$i];

                if ($counts[$name] > 1 || in_array($name, $explicit, true)) {
                    $name = self::_prefixed(self::_segments($expression['shape']), $name);
                }
            }

            for ($n = 2, $base = $name; in_array($name, $taken, true) || self::_isMarkupToken($name); $n++) {
                $name = $base . '_' . $n;
            }

            $taken[] = $name;
            $assigned[$expression['source']] ??= $name;
            $derived[$i]['name'] = $name;
        }

        $names = [];
        $explicitAt = 0;

        foreach ($expressions as $expression) {
            if (($expression['explicit'] ?? null) !== null) {
                $matches = array_values(array_filter($derived, fn ($e) => ($e['explicit'] ?? null) !== null));
                $names[] = $matches[$explicitAt++]['name'];
            } else {
                $names[] = $assigned[$expression['source']];
            }
        }

        return ['names' => $names, 'unnamed' => $unnamed];
    }

    /** `firstName` → `first_name`, `userID` → `user_id`. */
    public static function snake(string $name): string
    {
        $out = '';
        $length = strlen($name);

        for ($i = 0; $i < $length; $i++) {
            $char = $name[$i];
            $upper = ctype_upper($char);
            $previous = $i > 0 ? $name[$i - 1] : '';
            $next = $i + 1 < $length ? $name[$i + 1] : '';

            if ($upper && $i > 0 && $previous !== '_' && (ctype_lower($previous) || ctype_digit($previous) || ($next !== '' && ctype_lower($next)))) {
                $out .= '_';
            }

            $out .= ctype_alnum($char) ? strtolower($char) : '_';
        }

        $out = trim($out, '_');

        return $out !== '' && ctype_alpha($out[0]) ? $out : 'value';
    }

    /**
     * The PHP expression Blade echoes, read into the vectors' shapes: `$firstName`, `$user->name`
     * (also `?->` and `['name']`), `count($items)` and `$items->count()` as the length of `items`,
     * a call with one argument, and anything else as unnameable.
     */
    public static function shapeOf(string $expression): array
    {
        $tokens = array_values(array_filter(
            token_get_all('<?php ' . $expression . ';'),
            fn ($token) => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE], true)
        ));
        array_pop($tokens);

        return self::_shape($tokens) ?? ['other' => 'expression'];
    }

    private static function _shape(array $tokens): ?array
    {
        // A call with one argument: `f(arg)`, `Str::upper(arg)`, `count($items)`.
        $call = self::_call($tokens);

        if ($call !== null) {
            [$callee, $args] = $call;

            if (count($args) !== 1) {
                return ['call' => ['callee' => $callee, 'args' => array_map(fn () => ['other' => 'argument'], $args)]];
            }

            $arg = self::_shape($args[0]) ?? ['other' => 'argument'];

            // PHP's length of a collection is a call, where JavaScript's is a member.
            if (in_array(strtolower($callee), ['count', 'sizeof'], true) && isset($arg['identifier'])) {
                return ['member' => [$arg['identifier'], 'count']];
            }

            if (in_array(strtolower($callee), ['count', 'sizeof'], true) && isset($arg['member'])) {
                return ['member' => [...$arg['member'], 'count']];
            }

            return ['call' => ['callee' => $callee, 'args' => [$arg]]];
        }

        // `$var`, then `->name`, `?->name`, `['name']`, and a trailing `->name()` with no arguments.
        if (!isset($tokens[0]) || !is_array($tokens[0]) || $tokens[0][0] !== T_VARIABLE) {
            return null;
        }

        $segments = [substr($tokens[0][1], 1)];
        $count = count($tokens);

        for ($i = 1; $i < $count;) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING) {
                $segments[] = $tokens[$i + 1][1];
                $i += 2;

                if (($tokens[$i] ?? null) === '(' && ($tokens[$i + 1] ?? null) === ')') {
                    $i += 2;
                }

                continue;
            }

            if ($token === '[' && isset($tokens[$i + 1], $tokens[$i + 2]) && is_array($tokens[$i + 1])
                && $tokens[$i + 1][0] === T_CONSTANT_ENCAPSED_STRING && $tokens[$i + 2] === ']') {
                $segments[] = trim($tokens[$i + 1][1], '\'"');
                $i += 3;

                continue;
            }

            return ['other' => 'computed'];
        }

        return count($segments) === 1 ? ['identifier' => $segments[0]] : ['member' => $segments];
    }

    /** @return array{0: string, 1: list<array>}|null */
    private static function _call(array $tokens): ?array
    {
        $callee = '';
        $i = 0;
        $count = count($tokens);

        for (; $i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_DOUBLE_COLON], true); $i++) {
            $callee .= $tokens[$i][1];
        }

        if ($callee === '' || ($tokens[$i] ?? null) !== '(' || end($tokens) !== ')') {
            return null;
        }

        $args = [[]];
        $depth = 0;

        for ($j = $i + 1; $j < $count - 1; $j++) {
            $token = $tokens[$j];

            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($token === ',' && $depth === 0) {
                $args[] = [];

                continue;
            }

            $args[count($args) - 1][] = $token;
        }

        $args = array_values(array_filter($args, fn (array $arg) => $arg !== []));
        $segments = explode('::', $callee);

        return [end($segments), $args];
    }

    /** The segments a name can be built from, or null when the shape is unnameable. */
    private static function _segments(array $shape): ?array
    {
        if (isset($shape['identifier'])) {
            return [$shape['identifier']];
        }

        if (isset($shape['member'])) {
            return $shape['member'];
        }

        if (isset($shape['call']) && count($shape['call']['args']) === 1) {
            return self::_segments($shape['call']['args'][0]);
        }

        return null;
    }

    private static function _base(array $segments): string
    {
        $last = end($segments);
        $previous = count($segments) > 1 ? $segments[count($segments) - 2] : null;

        if ($previous !== null && in_array($last, self::COUNTS, true)) {
            return self::snake($previous) . '_count';
        }

        if ($previous !== null && in_array($last, self::UNWRAPS, true)) {
            return self::snake($previous);
        }

        return self::snake($last);
    }

    /** The name prefixed with the segment before those it was built from. */
    private static function _prefixed(array $segments, string $name): string
    {
        $used = in_array(end($segments), [...self::COUNTS, ...self::UNWRAPS], true) && count($segments) > 1 ? 2 : 1;
        $before = count($segments) - $used - 1;

        return $before >= 0 ? self::snake($segments[$before]) . '_' . $name : $name;
    }

    /** `m<N>o` and `m<N>c` are the `<Phrase>` markup tokens. */
    private static function _isMarkupToken(string $name): bool
    {
        $length = strlen($name);

        return $length >= 3 && $name[0] === 'm' && in_array($name[$length - 1], ['o', 'c'], true) && ctype_digit(substr($name, 1, -1));
    }
}
