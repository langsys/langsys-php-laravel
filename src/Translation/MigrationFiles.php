<?php

namespace Langsys\Laravel\Translation;

use Illuminate\Contracts\Translation\Loader;

/**
 * The SDK's migration option (MIG-7), read off the files Laravel's own loader would read in the
 * source locale, in the order Laravel would answer from them.
 *
 * The application's `lang/` directory is its own tier: `{locale}.json` first, since Laravel
 * answers a JSON line before a group line, then the group files. Every other path the loader
 * knows — the framework's bundled English, a package's JSON path — is the fallback tier, which
 * answers only what the application does not define, as a lang file overrides the framework's.
 * A package namespace gets the same two tiers: the application's `lang/vendor/{namespace}`
 * override, then the package's own files.
 *
 * `validation.php` is left out of every tier. Validation lines are the server-messages lane's,
 * built from the rule that failed, and Laravel's translator keeps answering those keys.
 */
final class MigrationFiles
{
    public static function for(Loader $loader, string $langPath, string $locale): array
    {
        $own = array_merge(self::_json($langPath, $locale), self::_groups($langPath, $locale));

        $fallback = [];
        $paths = method_exists($loader, 'paths') ? $loader->paths() : [$langPath];

        // The loader lets a later path override an earlier one, so the tier reads them last first.
        foreach (array_reverse($paths) as $path) {
            if (realpath($path) !== realpath($langPath)) {
                $fallback = array_merge($fallback, self::_groups($path, $locale));
            }
        }

        foreach (method_exists($loader, 'jsonPaths') ? $loader->jsonPaths() : [] as $path) {
            $fallback = array_merge($fallback, self::_json($path, $locale));
        }

        $namespaces = [];

        foreach ($loader->namespaces() as $namespace => $hint) {
            $namespaces[$namespace] = [
                'files'          => self::_groups("$langPath/vendor/$namespace", $locale),
                'fallback_files' => self::_groups($hint, $locale),
            ];
        }

        return [
            'files'          => $own,
            'fallback_files' => $fallback,
            'namespaces'     => $namespaces,
        ];
    }

    /**
     * Declared `laravel`: the SDK reads an undeclared JSON file as plain text, where a `|` plural
     * stays verbatim, and Laravel's JSON lines are Laravel's syntax. Group files need no
     * declaration, since `laravel` is the SDK's default for a PHP array.
     *
     * @return list<array{path: string, format: string}>
     */
    private static function _json(string $path, string $locale): array
    {
        return is_file("$path/$locale.json") ? [['path' => "$path/$locale.json", 'format' => 'laravel']] : [];
    }

    /** @return list<string> */
    private static function _groups(string $path, string $locale): array
    {
        $files = glob("$path/$locale/*.php") ?: [];

        return array_values(array_filter($files, fn (string $file) => basename($file) !== 'validation.php'));
    }
}
