<?php

namespace Langsys\Laravel\Support;

use BackedEnum;
use Langsys\SDK\Messages\TranslatableValues;
use Langsys\SDK\Messages\ValueSets;

/**
 * FRM-7's declarations, found the way Laravel finds its own event listeners: every class under
 * `app/` that implements the core's `TranslatableValues`, and every backed enum the core reports
 * a declared placeholder for (`#[TranslatesAs]`). `langsys.value_sets` adds classes kept elsewhere.
 *
 * Scanning loads every class it finds, so a production app caches the list, as `event:cache`
 * does: `php artisan langsys:cache` writes it to `bootstrap/cache`, and `optimize` runs it.
 */
final class ValueSetDiscovery
{
    /** @return list<class-string> The discovered declarations, cached when a cache exists, plus the configured ones. */
    public static function classes(): array
    {
        $cached = is_file(self::cachePath()) ? require self::cachePath() : null;
        $found = is_array($cached) ? $cached : self::within(app_path());

        return array_values(array_unique([...$found, ...(array) config('langsys.value_sets', [])]));
    }

    /** @return list<class-string> */
    public static function within(string $path): array
    {
        return ClassDiscovery::within($path, [self::class, 'isDeclaration']);
    }

    public static function isDeclaration(string $class): bool
    {
        if (!class_exists($class) && !enum_exists($class)) {
            return false;
        }

        return is_subclass_of($class, TranslatableValues::class)
            || (is_subclass_of($class, BackedEnum::class) && ValueSets::declaredPlaceholder($class) !== null);
    }

    /** @return list<class-string> */
    public static function cache(): array
    {
        $classes = self::within(app_path());
        @mkdir(dirname(self::cachePath()), 0777, true);
        file_put_contents(self::cachePath(), '<?php return ' . var_export($classes, true) . ';' . PHP_EOL);

        return $classes;
    }

    public static function clear(): void
    {
        @unlink(self::cachePath());
    }

    public static function cachePath(): string
    {
        return app()->bootstrapPath('cache/langsys-value-sets.php');
    }
}
