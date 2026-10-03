<?php

namespace Langsys\Laravel\View;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * VAR-5: Blade marks every value it prints in visible text, so a reader turns it back into a
 * placeholder (VAR-3) and `Hello {{ $user->name }}` is one phrase, `Hello {name}`, for every user.
 *
 * At compile time a precompiler reads the template's HTML context and rewrites each `{{ … }}` in
 * text to pass its value through `value()`, named after its expression (VAR-2), the names of one
 * phrase resolved together. It never touches an echo inside a tag (an attribute), inside `script`,
 * `style`, `title` or `textarea`, inside an HTML comment, `{!! … !!}`, or a translation call —
 * `__()` and its siblings print a word to translate, not a variable. At render time a value that is
 * already HTML (`Htmlable`: a slot, an `HtmlString`) prints unmarked, as output marked safe.
 *
 * Blade's own `{{ }}` compilation is kept: the rewritten echo is still an echo, so Laravel's escaping
 * and echo handlers apply; the marker is the value's own HTML.
 */
final class ValueMarker
{
    private const RAW_TEXT = ['script', 'style', 'title', 'textarea'];

    /** Elements a phrase runs through: they do not end it, so names resolve across them. */
    private const PHRASING = ['a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr'];

    private const TRANSLATION_CALLS = ['__(', '\\__(', 'trans(', '\\trans(', 'trans_choice(', '\\trans_choice(', 't(', '\\t(', 'Lang::', '\\Lang::', "app('translator')", 'app("translator")'];

    /** What a printed value renders as: the value, escaped as Blade escapes it, between the comment pair. */
    public static function value(string $name, mixed $value, bool $doubleEncode = true): mixed
    {
        if (!$value instanceof Htmlable && function_exists('app') && app()->bound('blade.compiler')) {
            $value = app('blade.compiler')->applyEchoHandler($value);
        }

        if ($value instanceof Htmlable) {
            return $value;
        }

        return new HtmlString('<!--ls:' . $name . '-->' . e($value, $doubleEncode) . '<!--/ls-->');
    }

    /**
     * The template with each echo in visible text routed through `value()`.
     *
     * @param  callable(string): void|null  $unnamed  Told each expression that could only be named `value`.
     */
    public static function precompile(string $template, bool $doubleEncode = true, ?callable $unnamed = null): string
    {
        $length = strlen($template);
        $out = '';
        $phrase = [];
        $rawText = null;
        $i = 0;

        $close = function () use (&$out, &$phrase, $doubleEncode, $unnamed) {
            if ($phrase === []) {
                return;
            }

            $named = PlaceholderNames::names(array_map(fn (array $echo) => ['shape' => PlaceholderNames::shapeOf($echo['expression']), 'source' => $echo['expression']], $phrase));

            foreach ($phrase as $n => $echo) {
                $replacement = '{{ \\' . self::class . "::value('" . $named['names'][$n] . "', " . $echo['expression'] . ($doubleEncode ? '' : ', false') . ') }}';
                $out = str_replace($echo['token'], $replacement, $out);
            }

            foreach ($named['unnamed'] as $source) {
                $unnamed !== null && $unnamed($source);
            }

            $phrase = [];
        };

        while ($i < $length) {
            // Inside a raw-text element nothing is text until its closing tag.
            if ($rawText !== null) {
                $end = stripos($template, '</' . $rawText, $i);
                $end = $end === false ? $length : $end;
                $out .= substr($template, $i, $end - $i);
                $i = $end;
                $rawText = null;

                continue;
            }

            if (substr_compare($template, '{!!', $i, 3) === 0) {
                $end = strpos($template, '!!}', $i + 3);
                $end = $end === false ? $length : $end + 3;
                $out .= substr($template, $i, $end - $i);
                $i = $end;

                continue;
            }

            if (substr_compare($template, '{{', $i, 2) === 0 && ($i === 0 || $template[$i - 1] !== '@')) {
                $end = strpos($template, '}}', $i + 2);

                if ($end === false) {
                    $out .= substr($template, $i);

                    break;
                }

                $source = substr($template, $i, $end + 2 - $i);
                $expression = trim(substr($template, $i + 2, $end - $i - 2));
                $i = $end + 2;

                if ($expression === '' || self::_isTranslation($expression)) {
                    $out .= $source;

                    continue;
                }

                // A token unique in the output, replaced once the phrase's names are known.
                $token = "\0ls" . count($phrase) . ':' . strlen($out) . "\0";
                $phrase[] = ['token' => $token, 'expression' => $expression];
                $out .= $token;

                continue;
            }

            if (substr_compare($template, '<!--', $i, 4) === 0) {
                $close();
                $end = strpos($template, '-->', $i + 4);
                $end = $end === false ? $length : $end + 3;
                $out .= substr($template, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($template[$i] === '<' && $i + 1 < $length && (ctype_alpha($template[$i + 1]) || $template[$i + 1] === '/' || $template[$i + 1] === '!')) {
                $end = self::_tagEnd($template, $i);
                $tag = substr($template, $i, $end - $i);
                $name = strtolower(self::_tagName($tag));

                if (!in_array($name, self::PHRASING, true)) {
                    $close();
                }

                $out .= $tag;
                $i = $end;

                if (in_array($name, self::RAW_TEXT, true) && $tag[1] !== '/') {
                    $rawText = $name;
                }

                continue;
            }

            $out .= $template[$i++];
        }

        $close();

        return $out;
    }

    private static function _isTranslation(string $expression): bool
    {
        foreach (self::TRANSLATION_CALLS as $call) {
            if (str_starts_with($expression, $call)) {
                return true;
            }
        }

        return false;
    }

    /** Where a tag ends: its `>`, outside quoted attribute values and outside Blade echoes. */
    private static function _tagEnd(string $template, int $start): int
    {
        $length = strlen($template);
        $quote = null;

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $template[$i];

            if ($quote === null && substr_compare($template, '{{', $i, 2) === 0) {
                $end = strpos($template, '}}', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i + 1;
            }
        }

        return $length;
    }

    private static function _tagName(string $tag): string
    {
        $name = '';

        for ($i = $tag[1] === '/' ? 2 : 1, $length = strlen($tag); $i < $length && (ctype_alnum($tag[$i]) || $tag[$i] === '-'); $i++) {
            $name .= $tag[$i];
        }

        return $name;
    }
}
