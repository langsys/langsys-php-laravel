<?php

namespace Langsys\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Langsys\Laravel\Messages\FormRequestSource;
use Langsys\Laravel\Support\ClientState;
use Langsys\Laravel\Support\LaravelLocales;
use Langsys\Laravel\Support\ValueSetDiscovery;
use Langsys\Laravel\Translation\MigrationFiles;
use Langsys\SDK\Client;
use Langsys\SDK\Messages\MessageCatalogCommand;
use Langsys\SDK\Sync\Planner;
use Langsys\SDK\Sync\SourceScanner;
use Langsys\SDK\Sync\SyncPlan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * FRM-2, the one way what passes through Laravel's translate function is registered: every literal
 * `__()`, `trans()`, `trans_choice()`, `@lang` and `t()` in the app's PHP and Blade, and every line
 * of its base-language files. A phrase the catalog holds is left alone; one the lang files hold
 * registers with the translations the other locales' files already have; anything else registers
 * alone. A call whose argument is not a literal is reported with its file and line, never
 * registered. The validation messages the app can send (MSG-7) register beside them.
 *
 * Scanning, planning and registering are the core's (`SourceScanner`, `planSync()`, `applySync()`);
 * this reads Laravel's files, compiles Blade with Laravel's own compiler, and names each target
 * locale's lang files as Laravel lays them out.
 */
class SyncCommand extends Command
{
    protected $signature = 'langsys:sync
        {--dry-run : List what would be registered, and register nothing}
        {--strict : Fail when a call cannot be registered ahead of time}
        {--watch : Sync again whenever a scanned file changes}
        {--interval=2 : Seconds between checks while watching}';

    protected $description = 'Register every phrase the app can show, with the translations its lang files already have';

    /** How many checks `--watch` makes before returning; null watches until stopped. For tests. */
    public static ?int $watchChecks = null;

    public function handle(): int
    {
        $status = $this->_sync();

        if (!$this->option('watch')) {
            return $status;
        }

        $this->line('Watching for changes. Press Ctrl+C to stop.');
        $seen = $this->_fingerprint();

        for ($checks = 0; self::$watchChecks === null || $checks < self::$watchChecks; $checks++) {
            // Waits for the interval, or for input, whichever comes first: no timer of its own.
            $input = [STDIN];
            $none = null;
            @stream_select($input, $none, $none, max(1, (int) $this->option('interval')));

            if (($now = $this->_fingerprint()) !== $seen) {
                $seen = $now;
                $status = $this->_sync();
            }
        }

        return $status;
    }

    private function _sync(): int
    {
        // Registering needs the project. A dry run without one plans offline: nothing is compared
        // with the catalog, so every phrase counts as new, and every check `--strict` makes still
        // runs — the gate a CI job with no secrets can hold.
        $online = ClientState::buildable($this->laravel);

        if (!$online && !$this->option('dry-run')) {
            $this->error('langsys:sync needs LANGSYS_API_KEY and LANGSYS_PROJECT_ID to register; --dry-run checks without them.');

            return self::FAILURE;
        }

        [$hits, $files] = $this->_hits();
        $options = ['covered_groups' => $this->_validatorGroups()];

        try {
            if ($online) {
                $client = $this->laravel->make(Client::class);
                $plan = $client->planSync($hits, $this->_targets($client), $options);
            } else {
                $this->line('No key: nothing is compared with the catalog, so every phrase counts as new.');
                $plan = Planner::offline($hits, $this->_migration(), ValueSetDiscovery::classes(), $options);
            }
        } catch (Throwable $e) {
            $this->error('Cannot plan the sync: ' . $e->getMessage());

            return self::FAILURE;
        }

        $messages = MessageCatalogCommand::collect([FormRequestSource::fromRoutes($this->laravel['router'])]);
        $this->_report($plan, $files, count($messages->templates()));

        // Validation messages: problems fail `--strict`, advice (MSG-10) never does.
        foreach ($messages->problems() as $problem) {
            $this->error('✗ ' . $problem);
        }

        foreach ($messages->advice() as $advice) {
            $this->line('  · ' . $advice);
        }

        $strict = $this->option('strict') && ($plan->failsStrict() || $messages->hasProblems());

        if ($this->option('dry-run')) {
            return $strict ? self::FAILURE : self::SUCCESS;
        }

        $result = $client->applySync($plan);

        if (!$result['success']) {
            $this->error('Nothing was registered: ' . $result['reason']);

            return self::FAILURE;
        }

        $this->info("Registered {$result['registered']} phrases, with {$result['translations']} existing translations.");

        try {
            $registered = MessageCatalogCommand::register($messages, $client);
            $this->info("Registered {$registered['registered']} validation messages.");
        } catch (Throwable $e) {
            $this->error('Validation messages were not registered: ' . $e->getMessage());

            return self::FAILURE;
        }

        return $strict ? self::FAILURE : self::SUCCESS;
    }

    private function _report(SyncPlan $plan, int $files, int $templates): void
    {
        $counts = $plan->counts();
        $this->line("Scanned $files files: {$counts['in_catalog']} phrases already in the catalog, {$counts['with_translations']} with translations from your lang files, {$counts['new']} new; $templates validation messages.");

        if ($this->output->isVerbose()) {
            foreach ($plan->toRegister() as $item) {
                $this->line('  ' . $item['phrase'] . ($item['category'] === null ? '' : "  <fg=gray>{$item['category']}</>"));
            }
        }

        // FRM-2: a line holding a label placeholder is a phrase no request looks up; each field's
        // sentence, the label written in, is what registers.
        foreach ($plan->viaValidation as $line) {
            $this->line("  {$line['origin']}: \"{$line['phrase']}\" holds {$line['placeholder']}, so it is registered through the validation listing, once per field");
        }

        // FRM-2: a key built at runtime inside a literal group names one of that group's lines,
        // every one of which is registered.
        foreach ($plan->covered as $call) {
            $this->line("  {$call['file']}:{$call['line']}: {$call['entry_point']}() builds its key at runtime, inside the {$call['group']} group, whose every line is registered");
        }

        foreach ($plan->reported as $call) {
            $this->warn("✗ {$call['file']}:{$call['line']}: {$call['entry_point']}() is called with something that is not a literal, so it cannot be registered ahead of time");
        }
    }

    /**
     * Every translate call in the app's PHP and Blade. Blade is compiled by Laravel's own
     * compiler, so `@lang`, `{{ __() }}` and `@choice` are read as Laravel runs them, and each hit's
     * line is found again in the view it came from.
     *
     * @return array{0: list<array>, 1: int}
     */
    private function _hits(): array
    {
        $scanner = new SourceScanner();
        $compiler = self::_scanningCompiler();
        $hits = [];
        $files = 0;

        foreach ($this->_files() as $path) {
            $files++;
            $source = (string) (new \SplFileObject($path))->fread(max(1, filesize($path)));
            $label = str_starts_with($path, base_path() . DIRECTORY_SEPARATOR) ? substr($path, strlen(base_path()) + 1) : $path;

            if (!str_ends_with($path, '.blade.php')) {
                array_push($hits, ...$scanner->scan($source, $label));

                continue;
            }

            array_push($hits, ...self::_sourceLines($source, $scanner->scan($compiler->compileString($source), $label)));
        }

        return [$hits, $files];
    }

    /**
     * A Blade compiler for reading, not rendering: `@lang` and `@t` compile to Laravel's own
     * `app('translator')->get(...)`, the call the scanner reads, where the rendering compiler
     * writes the escaping call instead. Nothing is cached.
     */
    private static function _scanningCompiler(): BladeCompiler
    {
        $compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
        $compiler->directive('t', fn (?string $expression) => "<?php echo app('translator')->get($expression); ?>");

        return $compiler;
    }

    /** @return list<string> */
    private function _files(): array
    {
        $files = [];

        foreach ((array) (config('langsys.sync_paths') ?: [app_path(), base_path('routes'), resource_path('views')]) as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The lang files of each locale the project targets, as Laravel lays them out, for the
     * translations a phrase already has. A target with no lang files contributes none.
     *
     * @return array<string, array>
     */
    private function _targets(Client $client): array
    {
        $project = $client->getProject();
        $targets = [];

        foreach ((array) ($project['target_locales'] ?? []) as $locale) {
            $spelling = LaravelLocales::inLangPath($this->laravel->langPath(), $locale);

            if ($spelling !== null) {
                $targets[$locale] = MigrationFiles::for($this->laravel['translation.loader'], $this->laravel->langPath(), $spelling);
            }
        }

        return $targets;
    }

    /** The base-language files, as the provider hands them to the client. */
    private function _migration(): ?array
    {
        return config('langsys.enabled')
            ? MigrationFiles::for($this->laravel['translation.loader'], $this->laravel->langPath(), $this->laravel['config']['app.fallback_locale'])
            : null;
    }

    /**
     * `validation` is the validator's group, never a migration file, so the plan is told it is
     * accounted for: its sentences register per field through the validation listing, and a key
     * built inside it (`__("validation.$key")`) names one of them (FRM-2).
     *
     * @return list<string>
     */
    private function _validatorGroups(): array
    {
        $locale = $this->laravel['config']['app.fallback_locale'];
        $framework = dirname((new \ReflectionClass(\Illuminate\Translation\Translator::class))->getFileName()) . "/lang/$locale/validation.php";

        return is_file($this->laravel->langPath("$locale/validation.php")) || is_file($framework) ? ['validation'] : [];
    }

    /** How each entry point is written in a Blade view, for finding a compiled hit's call again. */
    private const BLADE_CALLS = [
        '__'           => '/(?<![\w$>:@])__\s*\(/',
        'trans'        => '/(?<![\w$>:@])trans\s*\(/',
        'trans_choice' => '/(?<![\w$>:@])trans_choice\s*\(/',
        't'            => '/(?<![\w$>:@])t\s*\(/',
        'Lang::get'    => '/@lang\s*\(|@t\s*\(|Lang::get\s*\(/',
        'Lang::choice' => '/@choice\s*\(|Lang::choice\s*\(/',
    ];

    /**
     * A compiled view's lines are not the view's: Blade drops comments and rewrites directives.
     * The n-th call of a function in the compiled view is its n-th call in the source, so each hit
     * takes the line of its own call; when the counts disagree, a literal is found by its text.
     *
     * @param  list<array>  $hits
     * @return list<array>
     */
    private static function _sourceLines(string $source, array $hits): array
    {
        $calls = [];

        foreach (self::BLADE_CALLS as $entryPoint => $pattern) {
            preg_match_all($pattern, $source, $found, PREG_OFFSET_CAPTURE);
            $calls[$entryPoint] = array_map(fn (array $match) => substr_count($source, "\n", 0, $match[1]) + 1, $found[0]);
        }

        $seen = [];

        foreach ($hits as $i => $hit) {
            $entryPoint = $hit['entry_point'];
            $nth = $seen[$entryPoint] = ($seen[$entryPoint] ?? -1) + 1;
            $expected = count(array_filter($hits, fn (array $other) => $other['entry_point'] === $entryPoint));

            if (isset($calls[$entryPoint][$nth]) && count($calls[$entryPoint]) === $expected) {
                $hits[$i]['line'] = $calls[$entryPoint][$nth];
            } elseif ($hit['text'] !== null && ($at = strpos($source, $hit['text'])) !== false) {
                $hits[$i]['line'] = substr_count($source, "\n", 0, $at) + 1;
            }
        }

        return $hits;
    }

    private function _fingerprint(): string
    {
        $stamp = '';

        foreach ([...$this->_files(), ...glob($this->laravel->langPath() . '/{*,*/*,vendor/*/*/*}.{php,json}', GLOB_BRACE) ?: []] as $file) {
            $stamp .= $file . ':' . filemtime($file) . ';';
        }

        return $stamp;
    }
}
