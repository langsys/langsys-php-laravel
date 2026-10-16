<?php

namespace Langsys\Laravel\View;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Langsys\Laravel\Http\ResponseKind;
use Langsys\Laravel\Support\ClientState;

/**
 * VAR-5: Blade marks every value it prints in visible text, so a reader turns it back into a
 * placeholder (VAR-3) and `Hello {{ $user->name }}` is one phrase, `Hello {name}`, for every user.
 *
 * At compile time a precompiler reads the template's HTML context and rewrites each `{{ … }}` in
 * text to pass its value through `value()`, named after its expression (VAR-2), the names of one
 * phrase resolved together. Text is only what sits inside an HTML element the same template
 * opened: a value in a section, a slot, a component's body or at the top of a partial lands where
 * another template prints it, which could be a `<title>` or an attribute, so it is left as Blade
 * prints it. It never touches an echo inside a tag (an attribute, or a printed tag name), inside
 * `script`, `style`, `title` or `textarea`, inside an HTML comment, `{!! … !!}`, or a translation
 * call — `__()` and its siblings print a word to translate, not a variable. At render time a value that is
 * already HTML (`Htmlable`: a slot, an `HtmlString`) prints unmarked, as output marked safe.
 *
 * Blade's own `{{ }}` compilation is kept: the rewritten echo is still an echo, so Laravel's escaping
 * and echo handlers apply; the marker is the value's own HTML.
 */
final class ValueMarker
{
    /** Whether a streamed response that is not HTML is rendering. */
    private static bool $withoutMarks = false;

    /** What `marks()` decided for the tree of views rendering now. */
    private static ?bool $forTree = null;

    private const RAW_TEXT = ['script', 'style', 'title', 'textarea'];

    /** Elements with no content, which open nothing a value could sit in. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** Elements a phrase runs through: they do not end it, so names resolve across them. */
    private const PHRASING = ['a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr'];

    private const TRANSLATION_CALLS = ['__(', '\\__(', 'trans(', '\\trans(', 'trans_choice(', '\\trans_choice(', 't(', '\\t(', 'Lang::', '\\Lang::', "app('translator')", 'app("translator")'];

    /**
     * What a printed value renders as: the value, escaped as Blade escapes it, between the comment
     * pair, where the view marks its values at all (`marks()`); otherwise the value as Blade prints it.
     */
    public static function value(string $name, mixed $value, bool $doubleEncode = true, bool $marks = true): mixed
    {
        if (!$marks) {
            return $value;
        }

        if (!$value instanceof Htmlable && function_exists('app') && app()->bound('blade.compiler')) {
            $value = app('blade.compiler')->applyEchoHandler($value);
        }

        if ($value instanceof Htmlable) {
            return $value;
        }

        return new HtmlString('<!--ls:' . $name . '-->' . e($value, $doubleEncode) . '<!--/ls-->');
    }

    /**
     * The text with every marker removed and each value left as it was printed: what reaches a
     * reader that is not HTML's, such as a `robots.txt` or an XML feed a Blade view rendered.
     */
    public static function unmark(string $text): string
    {
        $out = '';
        $at = 0;

        while (($start = strpos($text, '<!--ls:', $at)) !== false && ($end = strpos($text, '-->', $start)) !== false) {
            $out .= substr($text, $at, $start - $at);
            $at = $end + 3;
        }

        return str_replace('<!--/ls-->', '', $out . substr($text, $at));
    }

    /**
     * Whether a view rendering now marks its values, asked at the top of each view.
     * Markers are for a page a reader walks, so a view marks nothing:
     * - while the package is switched off, since Laravel keeps a view compiled with it on;
     * - with no key, where the app's own tests read its pages as plain Laravel's (FRM-1);
     * - in a command or a job, which print for a terminal, an SMS or a file, never a page — an
     *   app's tests excepted, which render its pages from the console;
     * - while a streamed response that is not HTML renders (`withoutMarks()`);
     * - for mail or a notification, which its recipient reads as sent: a marker would show in a
     *   plain-text body, and at the start of a Markdown line it turns the line into raw HTML.
     * The last is read off the call stack once per tree of views: a view nested in another renders
     * for the same reader.
     */
    public static function marks(): bool
    {
        if (self::$withoutMarks || !function_exists('app')) {
            return false;
        }

        $app = app();

        // `buildable()` also answers no while the package is switched off.
        if (($app->runningInConsole() && !$app->runningUnitTests()) || !ClientState::buildable($app)) {
            return false;
        }

        if (self::$forTree !== null && (fn () => $this->renderCount)->call($app['view']) > 1) {
            return self::$forTree;
        }

        return self::$forTree = !ResponseKind::forRecipient();
    }

    /** Renders with no value marked: a streamed response that is not HTML, rendered as it is sent. */
    public static function withoutMarks(callable $render): mixed
    {
        $outer = self::$withoutMarks;
        self::$withoutMarks = true;

        try {
            return $render();
        } finally {
            self::$withoutMarks = $outer;
        }
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
        // The HTML elements this template has opened and not closed: a value is in visible text
        // only inside one. Anywhere else — a section, a slot, a component's body, the top of a
        // partial — the template that prints it decides its context, which could be a `<title>`,
        // a `<textarea>`, a script or an attribute, so the value is printed as Blade prints it.
        $open = [];
        // Each component or slot body entered, with the elements open around it: its body prints
        // where the component's own template puts it, so it starts with none open.
        $bodies = [];
        $i = 0;

        $close = function () use (&$out, &$phrase, $doubleEncode, $unnamed) {
            if ($phrase === []) {
                return;
            }

            $named = PlaceholderNames::names(array_map(fn (array $echo) => ['shape' => PlaceholderNames::shapeOf($echo['expression']), 'source' => $echo['expression']], $phrase));

            foreach ($phrase as $n => $echo) {
                $replacement = '{{ \\' . self::class . "::value('" . $named['names'][$n] . "', " . $echo['expression'] . ', ' . ($doubleEncode ? 'true' : 'false') . ', $__lsMarks) }}';
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

            // A raw echo, and Blade's legacy escaped `{{{ … }}}`, print as Blade prints them.
            if (($legacy = substr_compare($template, '{{{', $i, 3) === 0) || substr_compare($template, '{!!', $i, 3) === 0) {
                $end = strpos($template, $legacy ? '}}}' : '!!}', $i + 3);
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

                // Blade drops an echo's trailing semicolon (`{{ $x; }}`), and so must the argument.
                if (str_ends_with($expression, ';')) {
                    $expression = rtrim(substr($expression, 0, -1));
                }
                $i = $end + 2;

                if ($expression === '' || $open === [] || self::_isTranslation($expression)) {
                    $out .= $source;

                    continue;
                }

                // A token unique in the output, replaced once the phrase's names are known.
                $token = "\0ls" . count($phrase) . ':' . strlen($out) . "\0";
                $phrase[] = ['token' => $token, 'expression' => $expression];
                $out .= $token;

                continue;
            }

            // Blade has compiled a component tag to `@component(…)` … `@endComponentClass`, and a slot
            // to `@slot(…)` … `@endslot`, before any precompiler runs; a legacy `@component` is the same.
            if ($template[$i] === '@' && ($i === 0 || $template[$i - 1] !== '@') && ($body = self::_body($template, $i)) !== null) {
                $close();
                [$enters, $kind, $end] = $body;
                $out .= substr($template, $i, $end - $i);
                $i = $end;

                if ($enters) {
                    $bodies[] = [$kind, $open];
                    $open = [];

                    continue;
                }

                // A slot given its content inline (`@slot('title', 'x')`) has no end of its own, so
                // the component's end also leaves whatever slot is still entered.
                while ($bodies !== []) {
                    [$entered, $open] = array_pop($bodies);

                    if ($entered === $kind) {
                        break;
                    }
                }

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

            // A tag, its name written out or printed (`<{{ $as }} …>`).
            if ($template[$i] === '<' && $i + 1 < $length && (ctype_alpha($template[$i + 1]) || $template[$i + 1] === '/' || $template[$i + 1] === '!' || substr_compare($template, '{{', $i + 1, 2) === 0)) {
                $end = self::_tagEnd($template, $i);
                $tag = substr($template, $i, $end - $i);
                $name = strtolower(self::_tagName($tag));

                if (!in_array($name, self::PHRASING, true)) {
                    $close();
                }

                $out .= $tag;
                $i = $end;

                if ($tag[1] === '/') {
                    $at = array_search($name, $open, true) === false ? null : max(array_keys($open, $name, true));
                    $at !== null && array_splice($open, $at);
                } elseif (in_array($name, self::RAW_TEXT, true)) {
                    $rawText = $name;
                } elseif ($name !== '' && $tag[1] !== '!' && !str_ends_with($tag, '/>') && !in_array($name, self::VOID, true) && !self::_isComponent($tag, $name)) {
                    $open[] = $name;
                }

                continue;
            }

            $out .= $template[$i++];
        }

        $close();

        // Asked on the first line of every view, so no line number moves, and so the outermost view
        // of a tree always asks first, whether it prints a value or not.
        return '<?php $__lsMarks = \\' . self::class . '::marks(); ?>' . $out;
    }

    /**
     * Whether a directive at `@` enters or leaves a component or slot body, which kind of body, and
     * where the directive ends; null for any other directive. Blade calls a method named after the
     * directive, so the name is matched as PHP matches a method's.
     *
     * @return array{0: bool, 1: string, 2: int}|null
     */
    private static function _body(string $template, int $at): ?array
    {
        $length = strlen($template);

        for ($i = $at + 1; $i < $length && (ctype_alnum($template[$i]) || $template[$i] === '_'); $i++) {
        }

        [$enters, $kind] = match (strtolower(substr($template, $at + 1, $i - $at - 1))) {
            'component', 'componentfirst' => [true, 'component'],
            'slot' => [true, 'slot'],
            'endcomponent', 'endcomponentclass', 'endcomponentfirst' => [false, 'component'],
            'endslot' => [false, 'slot'],
            default => [null, null],
        };

        if ($enters === null) {
            return null;
        }

        if (!$enters) {
            return [false, $kind, $i];
        }

        $open = self::_directiveParen($template, $at);

        return $open === null ? null : [true, $kind, self::_closingParen($template, $open) + 1];
    }

    /** A Blade or Livewire component tag (`<x-card>`, `<x-slot:title>`, `<livewire:counter>`), which is no element of the page. */
    private static function _isComponent(string $tag, string $name): bool
    {
        return str_starts_with($name, 'x-') || ($tag[1 + strlen($name)] ?? '') === ':';
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

    /**
     * Where a tag ends: its `>`, outside quoted attribute values, Blade echoes and a directive's
     * arguments, whose PHP holds `->` and `=>` (`@disabled($user->locked)`, `@class([… => …])`).
     */
    private static function _tagEnd(string $template, int $start): int
    {
        $length = strlen($template);
        $quote = null;

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $template[$i];

            if ($quote === null && ($echo = substr_compare($template, '{{', $i, 2) === 0 ? '}}' : (substr_compare($template, '{!!', $i, 3) === 0 ? '!!}' : null)) !== null) {
                $end = strpos($template, $echo, $i + 2);
                $i = $end === false ? $length : $end + strlen($echo) - 1;

                continue;
            }

            if ($quote === null && $char === '@' && ($open = self::_directiveParen($template, $i)) !== null) {
                $i = self::_closingParen($template, $open);

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

    /** Where the arguments of a directive starting at `@` open (`@class (`), or null when no `(` follows its name. */
    private static function _directiveParen(string $template, int $at): ?int
    {
        $length = strlen($template);
        $i = $at + 1;

        while ($i < $length && (ctype_alnum($template[$i]) || $template[$i] === '_' || $template[$i] === ':')) {
            $i++;
        }

        if ($i === $at + 1) {
            return null;
        }

        while ($i < $length && ($template[$i] === ' ' || $template[$i] === "\t")) {
            $i++;
        }

        return $i < $length && $template[$i] === '(' ? $i : null;
    }

    /** The `)` that closes the `(` at $open, past PHP strings and nested parentheses. */
    private static function _closingParen(string $template, int $open): int
    {
        $length = strlen($template);
        $depth = 0;
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
            $char = $template[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
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
