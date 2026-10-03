<?php

namespace Langsys\Laravel\Support;

use Illuminate\Contracts\Validation\InvokableRule;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Langsys\SDK\Messages\HasAppMessageTemplate;
use ReflectionClass;

/**
 * MSG-7's app messages, found as FRM-7's value sets are: every concrete class under `app/` that
 * implements the core's `HasAppMessageTemplate`, plus `langsys.messages.classes` for classes kept
 * elsewhere. A class that is also a validation rule is left to the per-field listing (FRM-2), so
 * it is never listed twice. `php artisan langsys:cache` caches the scan, as it does value sets.
 */
final class AppMessageDiscovery
{
    /** @return list<class-string> The discovered app messages, cached when a cache exists, plus the configured ones. */
    public static function classes(): array
    {
        $cached = is_file(self::cachePath()) ? require self::cachePath() : null;
        $found = is_array($cached) ? $cached : self::within(app_path());

        return array_values(array_unique([...$found, ...(array) config('langsys.messages.classes', [])]));
    }

    /** @return list<class-string> */
    public static function within(string $path): array
    {
        return ClassDiscovery::within($path, [self::class, 'isDeclaration']);
    }

    public static function isDeclaration(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, HasAppMessageTemplate::class) || (new ReflectionClass($class))->isAbstract()) {
            return false;
        }

        return !is_subclass_of($class, ValidationRule::class) && !is_subclass_of($class, Rule::class) && !is_subclass_of($class, InvokableRule::class);
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
        return app()->bootstrapPath('cache/langsys-app-messages.php');
    }
}
