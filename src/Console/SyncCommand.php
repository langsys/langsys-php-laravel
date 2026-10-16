<?php

namespace Langsys\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Compilers\BladeCompiler;
use Langsys\Laravel\LangsysTranslator;
use Langsys\Laravel\Messages\AppMessageSource;
use Langsys\Laravel\Messages\FormRequestSource;
use Langsys\Laravel\Support\AppMessageDiscovery;
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
 * FRM-2, how what passes through Laravel's translate function is registered: every literal
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

    /**
     * Told the number of each check `--watch` has made; false stops watching. Null watches until
     * stopped. For tests.
     *
     * @var (\Closure(int): bool)|null
     */
    public static ?\Closure $watching = null;

    public function handle(): int
    {
        // Off, the client has no base-language lines, so a plan would hold only part of the app.
        if (!config('langsys.enabled')) {
            $this->warn('Langsys is switched off (LANGSYS_ENABLED=false), so langsys:sync reads and registers nothing.');

            return self::SUCCESS;
        }

        $status = $this->_sync();

        if (!$this->option('watch')) {
            return $status;
        }

        // PHP loads a class once per process and Laravel reads its routes once as it boots, so an
        // edited class — a form request's rules, an app message, a value set — or a routes file is
        // read by the next run, not the next check.
        $this->line('Watching for changes. Press Ctrl+C to stop, and run it again after changing a PHP class or a routes file: each is read once per run.');
        $seen = $this->_fingerprint();

        // The interval is a developer command's poll, not a network timer, and the binding keeps no
        // timer of its own (BIND-3): it is waited out as the timeout of a select on a stream nothing
        // writes to, so nothing the terminal holds cuts it short.
        $quiet = stream_socket_pair(DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        for ($check = 1; ; $check++) {
            $wait = [$quiet[0]];
            $none = null;
            @stream_select($wait, $none, $none, max(1, (int) $this->option('interval')));

            if (($now = $this->_fingerprint()) !== $seen) {
                $seen = $now;

                // The client reads the base-language files once, as it is built, and Laravel's
                // translator keeps every line it has loaded, the validator's included, so each sync
                // after a change reads them again: with a client built afresh from the binding that
                // built the first, and the translator's lines dropped.
                if (array_key_exists(Client::class, $this->laravel->getBindings())) {
                    $this->laravel->forgetInstance(Client::class);
                    $this->laravel->forgetInstance(LangsysTranslator::class);
                }

                $this->laravel['translator']->setLoaded([]);

                $status = $this->_sync();
            }

            if (self::$watching !== null && !(self::$watching)($check)) {
                return $status;
            }
        }
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

        [$hits, $files, $unreadable] = $this->_hits();
        $messages = MessageCatalogCommand::collect([FormRequestSource::fromRoutes($this->laravel['router']), AppMessageSource::discovered()]);

        // FRM-2, MSG-7: a call the messages listing already owns — inside an app message's
        // template(), or whose sentence the listing lists — is that listing's, never a bare phrase.
        $options = [
            'covered_groups'   => $this->_validatorGroups(),
            'message_classes'  => AppMessageDiscovery::classes(),
            'listed_templates' => array_column($messages->templates(), 'template'),
        ];

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

        $this->_report($plan, $files, count($messages->templates()));

        foreach ($unreadable as $view) {
            $this->error('✗ ' . $view);
        }

        // Validation messages: problems fail `--strict`, advice (MSG-10) never does.
        foreach ($messages->problems() as $problem) {
            $this->error('✗ ' . $problem);
        }

        foreach ($messages->advice() as $advice) {
            $this->line('  · ' . $advice);
        }

        $strict = $this->option('strict') && ($plan->failsStrict() || $messages->hasProblems() || $unreadable !== []);

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

        // MSG-7: a call inside an app message's template method is that message's listing.
        foreach ($plan->viaMessageListing as $call) {
            $this->line($call['class'] !== null
                ? "  {$call['file']}:{$call['line']}: {$call['entry_point']}() states {$call['class']}'s message, registered through the message listing"
                : "  {$call['file']}:{$call['line']}: {$call['entry_point']}() prints \"{$call['phrase']}\", a message the listing registers under its category");
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
     * @return array{0: list<array>, 1: int, 2: list<string>}
     */
    private function _hits(): array
    {
        $scanner = new SourceScanner();
        $compiler = self::_scanningCompiler();
        $hits = [];
        $files = 0;
        $unreadable = [];

        foreach ($this->_files() as $path) {
            $files++;
            $source = (string) (new \SplFileObject($path))->fread(max(1, filesize($path)));
            $label = str_starts_with($path, base_path() . DIRECTORY_SEPARATOR) ? substr($path, strlen(base_path()) + 1) : $path;

            if (!str_ends_with($path, '.blade.php')) {
                array_push($hits, ...$scanner->scan($source, $label));

                continue;
            }

            // A view Blade cannot compile — a component the app no longer has — is reported, and
            // the rest are read; the app never compiles a view it never renders.
            try {
                $compiled = self::_blocks($compiler->compileString($source));
            } catch (Throwable $e) {
                $unreadable[] = "$label cannot be read: " . $e->getMessage();

                continue;
            }

            array_push($hits, ...self::_sourceLines($source, $scanner->scan($compiled, $label)));
        }

        return [$hits, $files, $unreadable];
    }

    /**
     * The app's own Blade compiler, for reading, not rendering: every directive, component and
     * precompiler the app and its packages registered reads a view as the page does, so a call
     * inside them is read too, and `@lang`, `@t` and `@choice` compile as the page compiles them,
     * a line to `app('translator')->getHtml(...)` or `choiceHtml(...)`, which the core reads as the
     * rich line it is (FRM-8). The value marker is this package's and rendering's, so a scan runs
     * without it and logs nothing rendering would. The block form is marked where Blade itself
     * finds the directive, for `_blocks()` to read. Nothing is cached.
     */
    private static function _scanningCompiler(): BladeCompiler
    {
        $compiler = clone Blade::getFacadeRoot();

        (function () {
            $this->precompilers = array_values(array_filter($this->precompilers, fn (callable $precompiler) => !$precompiler instanceof \Closure
                || (new \ReflectionFunction($precompiler))->getClosureScopeClass()?->getName() !== \Langsys\Laravel\LangsysServiceProvider::class));
        })->call($compiler);

        $inline = $compiler->getCustomDirectives()['lang'];

        foreach (['lang', 't'] as $directive) {
            $compiler->directive($directive, function (?string $expression) use ($inline) {
                $expression = trim((string) $expression);

                return $expression === '' || str_starts_with($expression, '[')
                    ? '<?php /*' . self::BLOCK . base64_encode($expression === '' ? '[]' : $expression) . '*/ ?>'
                    : $inline($expression);
            });
            $compiler->directive("end$directive", fn () => self::BLOCK_END);
        }

        return $compiler;
    }

    /**
     * Each block, `@lang … @endlang` or `@t … @endt`, as the call it prints: `getHtml('text',
     * values)`, its text trimmed as Laravel trims it, so the scan reads a literal line as any
     * other. A block holding PHP — an echo, a directive, another block — prints text known only as
     * it renders, so it is read as a call with something that is not a literal, which `--strict`
     * reports, and what it holds is read after it. Innermost blocks first, so a block inside
     * another is read on its own.
     */
    private static function _blocks(string $compiled): string
    {
        $open = '<?php /*' . self::BLOCK;

        while (($end = strpos($compiled, self::BLOCK_END)) !== false) {
            $start = strrpos(substr($compiled, 0, $end), $open);

            if ($start === false) {
                $compiled = substr_replace($compiled, '', $end, strlen(self::BLOCK_END));

                continue;
            }

            $body = strpos($compiled, '*/ ?>', $start) + 5;
            $values = base64_decode(substr($compiled, $start + strlen($open), $body - 5 - $start - strlen($open)));
            $text = substr($compiled, $body, $end - $body);
            // A block holding PHP keeps it after its own call, for the calls inside to be read too.
            $call = str_contains($text, '<?')
                ? "<?php echo app('translator')->getHtml(\$__lsBlockText); ?>" . $text
                : "<?php echo app('translator')->getHtml(" . var_export(trim($text), true) . ", $values); ?>";
            $compiled = substr($compiled, 0, $start) . $call . substr($compiled, $end + strlen(self::BLOCK_END));
        }

        return $compiled;
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
    private function _migration(): array
    {
        return MigrationFiles::for($this->laravel['translation.loader'], $this->laravel->langPath(), $this->laravel['config']['app.fallback_locale']);
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

    /** What marks a block's start and end in a view compiled for reading. */
    private const BLOCK = 'ls-block:';
    private const BLOCK_END = '<?php /*/ls-block*/ ?>';

    /**
     * How each entry point is written in a Blade view, for finding a compiled hit's call again:
     * every `@lang` and `@t` is one call, written inline or opening a block.
     */
    private const BLADE_CALLS = [
        '__'           => '/(?<![\w$>:@])__\s*\(/',
        'trans'        => '/(?<![\w$>:@])trans\s*\(/',
        'trans_choice' => '/(?<![\w$>:@])trans_choice\s*\(/',
        't'            => '/(?<![\w$>:@])t\s*\(/',
        'Lang::get'    => '/(?<![\w@])@(?:lang|t)\b|Lang::get\s*\(/',
        'Lang::choice' => '/(?<![\w@])@choice\s*\(|Lang::choice\s*\(/',
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
        // Blade drops its comments, so a call written in one is none: each is blanked, its lines kept.
        $read = preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $comment) => str_repeat("\n", substr_count($comment[0], "\n")), $source);

        foreach (self::BLADE_CALLS as $entryPoint => $pattern) {
            preg_match_all($pattern, $read, $found, PREG_OFFSET_CAPTURE);
            $calls[$entryPoint] = array_map(fn (array $match) => substr_count($read, "\n", 0, $match[1]) + 1, $found[0]);
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

        // One pattern per depth and extension: GLOB_BRACE is not defined where PHP is built on musl.
        $lang = [];

        foreach (['/*', '/*/*', '/vendor/*/*/*'] as $depth) {
            foreach (['.php', '.json'] as $extension) {
                array_push($lang, ...(glob($this->laravel->langPath() . $depth . $extension) ?: []));
            }
        }

        foreach ([...$this->_files(), ...$lang] as $file) {
            $stamp .= $file . ':' . filemtime($file) . ';';
        }

        return $stamp;
    }
}
