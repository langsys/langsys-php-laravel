<?php

namespace Langsys\Laravel\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The classes a directory declares, found the way Laravel finds its own event listeners: each PHP
 * file's tokens name the class, interface or enum it declares, so no path layout is assumed. What
 * counts is the caller's: FRM-7's value sets and MSG-7's app messages each pass their own test.
 *
 * @internal
 */
final class ClassDiscovery
{
    /**
     * @param  callable(class-string): bool  $accepts
     * @return list<class-string>
     */
    public static function within(string $path, callable $accepts): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $classes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || ($class = self::_declaredClass((string) $file->openFile()->fread(max(1, $file->getSize())))) === null) {
                continue;
            }

            if ($accepts($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /** The class, interface or enum a file declares, read from its tokens. */
    private static function _declaredClass(string $code): ?string
    {
        $tokens = token_get_all($code);
        $namespace = '';

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    $namespace .= is_array($tokens[$j]) ? trim($tokens[$j][1]) : '';
                }
            }

            if (in_array($token[0], [T_CLASS, T_ENUM], true) && ($tokens[$i - 1][0] ?? null) !== T_DOUBLE_COLON) {
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        return ltrim($namespace . '\\' . $tokens[$j][1], '\\');
                    }
                }
            }
        }

        return null;
    }
}
