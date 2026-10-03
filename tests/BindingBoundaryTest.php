<?php

namespace Langsys\Laravel\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * Absence probes for everything this package delegates to langsys/langsys-php.
 *
 * A binding may adapt shape and timing, never meaning (BIND-1). The runtime
 * tests prove what the binding does; these prove what it cannot do, by reading
 * its source for the constructs a reimplementation would need. Every family is
 * paired with a firing control that runs the same scan over a planted
 * violation, so a scan that matches nothing cannot pass, and over the same
 * word in a comment, so one that matches everything cannot either.
 *
 * Tokens, not text: this package's comments name the SDK behaviour they
 * delegate (`write_enabled`, `__uncategorized__`), and a grep would flag the
 * explanation instead of the code.
 */
class BindingBoundaryTest extends TestCase
{
    /** BIND-2: never read or branch on server-computed capability (GATE, REG-1, OBS-1). */
    private const CAPABILITY = [
        'write_enabled', 'writeenabled', 'key_type', 'keytype', 'canwrite', 'resolvewritedecision',
        'auto_discovery', 'ip_write',
    ];

    /** BIND-3: no transport, request construction, headers, retries, timers or batching (WIRE-1/2/5, REG-2…9, HINT-2, GRANT). */
    private const NETWORK = [
        'curl_init', 'curl_exec', 'curl_setopt', 'curl_multi_exec', 'fsockopen', 'stream_socket_client',
        'stream_context_create', 'file_get_contents', 'fopen', 'sleep', 'usleep', 'guzzlehttp',
        'illuminate\\support\\facades\\http', 'x-authorization', 'x-write-grant', 'discovery/hint',
        'createphrases', 'createcontentblocks', 'registerphrases', 'registercontentblock',
        'queuephraseforregistration', 'queuecontentblockforregistration', 'clearpendingregistrations',
    ];

    /** BIND-1 over identity and rendering: hashing, tokenizing, stamping, interpolating, catalog namespaces (CID, TOK, MARK, ICU, CAT, WIRE-3). */
    private const MEANING = [
        'md5', 'sha1', 'hash', 'crc32', 'json_encode', 'domdocument', 'domxpath', 'htmlparser',
        'pagetranslator', 'markuptokenizer', 'interpolator', 'getinterpolator', 'messageformatter',
        'preg_replace', 'preg_match', 'mb_ereg_replace', '__uncategorized__', 'data-ls-', 'data-langsys-',
        'getpendingphrases', 'getpendingcontentblocks', 'translatecontentblock',
        // MSG-2: a code is Laravel's own rule name. A shared size vocabulary would be a mapping of ours.
        'too_short', 'too_long', 'too_small', 'too_large', 'too_few', 'too_many',
    ];

    /**
     * BIND-6: the core's surface re-exported, plus framework idioms. Pinned so
     * that a new public name has to be argued for here rather than arriving
     * unnoticed. Methods declared by each class itself.
     */
    private const PUBLIC_SURFACE = [
        'Langsys\\Laravel\\Cache\\LaravelCacheAdapter'                  => ['__construct', 'clear', 'delete', 'get', 'has', 'set'],
        'Langsys\\Laravel\\Console\\CacheCommand'                       => ['handle'],
        'Langsys\\Laravel\\Console\\ClearCommand'                       => ['handle'],
        'Langsys\\Laravel\\Console\\MessagesCommand'                    => ['handle'],
        'Langsys\\Laravel\\Console\\SyncCommand'                        => ['handle'],
        'Langsys\\Laravel\\Facades\\Langsys'                            => [],
        'Langsys\\Laravel\\Http\\Middleware\\AttachServerMessages'      => ['handle'],
        'Langsys\\Laravel\\Http\\Middleware\\DetectLocale'              => ['__construct', 'handle'],
        'Langsys\\Laravel\\Http\\Middleware\\FlushPendingRegistrations' => ['__construct', 'handle', 'terminate'],
        'Langsys\\Laravel\\Http\\Middleware\\MarkResolvedPage'          => ['handle'],
        'Langsys\\Laravel\\Http\\Middleware\\TranslateResponse'         => ['__construct', 'handle'],
        'Langsys\\Laravel\\Http\\ResponseKind'                         => ['current', 'of', 'sending', 'sent'],
        'Langsys\\Laravel\\LangsysServiceProvider'                      => ['boot', 'register'],
        'Langsys\\Laravel\\LangsysTranslator'                           => ['__construct', 'client', 'resolve', 'translate', 'translateRich'],
        'Langsys\\Laravel\\Messages\\RuleWording'                       => ['placeholders'],
        'Langsys\\Laravel\\Messages\\MessageValidator'                   => ['passes', 'serverMessages'],
        'Langsys\\Laravel\\Messages\\AppMessageSource'                   => ['__construct', 'collect', 'discovered'],
        'Langsys\\Laravel\\Messages\\FormRequestSource'                  => ['__construct', 'collect', 'fromRoutes'],
        'Langsys\\Laravel\\Messages\\ValidatorMessages'                  => ['forRule', 'fromValidator'],
        'Langsys\\Laravel\\Support\\AppMessageDiscovery'                => ['cache', 'cachePath', 'classes', 'clear', 'isDeclaration', 'within'],
        'Langsys\\Laravel\\Support\\ClassDiscovery'                     => ['within'],
        'Langsys\\Laravel\\Support\\ClientState'                        => ['buildable'],
        'Langsys\\Laravel\\Support\\InertiaSsrProps'                    => ['share'],
        'Langsys\\Laravel\\Support\\LaravelLocales'                     => ['candidates', 'inLangPath'],
        'Langsys\\Laravel\\Support\\LocaleFormatter'                    => ['canonicalize'],
        'Langsys\\Laravel\\Support\\RequestLocaleWiring'                => ['headerOnly', 'options'],
        'Langsys\\Laravel\\Support\\ValueSetDiscovery'                  => ['cache', 'cachePath', 'classes', 'clear', 'isDeclaration', 'within'],
        'Langsys\\Laravel\\Translation\\CatalogTranslator'              => ['__construct', 'choice', 'fallbackLine', 'get', 'getHtml', 'has'],
        'Langsys\\Laravel\\Translation\\MigrationFiles'                 => ['for'],
        'Langsys\\Laravel\\View\\PlaceholderNames'                     => ['names', 'shapeOf', 'snake'],
        'Langsys\\Laravel\\View\\ValueMarker'                          => ['precompile', 'value'],
    ];

    /**
     * BIND-4: every key maps an option the core defines onto Laravel, or
     * decides whether and where Laravel invokes the core — never what the core
     * does once invoked.
     */
    private const CONFIG_SURFACE = [
        'api_key'                     => 'core option',
        'api_url'                     => 'core option',
        'cache.prefix'                => 'core `cache` option, mapped onto a Laravel store',
        'cache.store'                 => 'core `cache` option, mapped onto a Laravel store',
        'cache.ttl'                   => 'core cache TTL (Config::getCacheTtl), applied by the Laravel store adapter',
        'enabled'                     => 'the off switch FRM-1 calls for: whether Laravel invokes the core at all',
        'locale.cookie'               => 'SRV-6 wiring: the cookie the request locale is kept in',
        'locale.cookie_minutes'       => 'SRV-6 wiring: the lifetime of that cookie',
        'locale.persist'              => 'SRV-6 wiring: where a query-string choice is kept',
        'locale.query_param'          => 'SRV-6 wiring: the query parameter the app routes by',
        'locale.session_key'          => 'SRV-6 wiring: the session key the request locale is kept in',
        'locale.sources'              => 'SRV-6 wiring: which of the app\'s sources are read, in its order',
        'locale.supported'            => 'SRV-6 wiring: the app\'s own narrowing of the project\'s locales',
        'messages.category'           => "the core's messages_category option",
        'messages.classes'            => 'MSG-7 wiring: app messages kept outside app/, as value_sets is for FRM-7',
        'messages.pieces'             => 'the names of an entry\'s pieces in the application\'s own error body (MSG-1)',
        'messages.response_key'       => 'where the entries sit in the application\'s own error body (MSG-1)',
        'project_id'                  => 'core option',
        'response_kinds'              => 'FRM-4 wiring: the kind of response a route group serves, when the app names it',
        'snapshot'                    => "core `snapshot` option, the file Laravel's config names (SNAP-2)",
        'sync_paths'                  => 'FRM-2 wiring: where the app keeps the source langsys:sync reads',
        'translate_response.category' => 'the $category argument of translatePage()',
        'translate_response.enabled'  => 'whether Laravel invokes translatePage()',
        'translate_response.except'   => 'on which routes Laravel invokes translatePage()',
        'translate_response.only'     => 'on which routes Laravel invokes translatePage()',
        'value_sets'                  => "the core's `value_sets` option: declared sets kept outside app/ (FRM-7)",
    ];

    public function testTheBindingNeverTouchesServerComputedCapability(): void
    {
        $this->assertSame([], $this->_scanSource(self::CAPABILITY));
    }

    public function testTheBindingOwnsNoNetworkBehaviour(): void
    {
        $this->assertSame([], $this->_scanSource(self::NETWORK));
    }

    public function testTheBindingReimplementsNoIdentityOrRenderingBehaviour(): void
    {
        $this->assertSame([], $this->_scanSource(self::MEANING));
    }

    public function testEveryScanFiresOnAPlantedViolationAndNotOnAComment(): void
    {
        foreach (['CAPABILITY' => self::CAPABILITY, 'NETWORK' => self::NETWORK, 'MEANING' => self::MEANING] as $family => $needles) {
            foreach ($needles as $needle) {
                foreach (self::_plantings($needle) as $form => $planted) {
                    $this->assertContains($needle, self::_scan($planted, $needles), "{$family}: the scan cannot see `{$needle}` as {$form}.");
                }

                $this->assertNotContains(
                    $needle,
                    self::_scan("<?php\n// {$needle}\n/* {$needle} */\n/** {$needle} */\n", $needles),
                    "{$family}: the scan flags `{$needle}` inside a comment."
                );
            }
        }
    }

    /** The scans above are only as good as the files they read: every class on the surface, plus the helper. */
    public function testTheScanReadsEverySourceFile(): void
    {
        $files = array_map(fn ($file) => substr($file, strlen($this->_root()) + 1), $this->_phpFiles($this->_root() . '/src'));

        $expected = array_map(
            fn ($class) => 'src/' . str_replace('\\', '/', substr($class, strlen('Langsys\\Laravel\\'))) . '.php',
            array_keys(self::PUBLIC_SURFACE)
        );
        $expected[] = 'src/helpers.php';
        sort($expected);

        $this->assertSame($expected, $files);
    }

    public function testThePublicSurfaceIsTheOneArguedForHere(): void
    {
        $surface = [];

        foreach (array_keys(self::PUBLIC_SURFACE) as $class) {
            $methods = array_filter(
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method) => $method->class === $class
            );
            $names = array_map(fn (ReflectionMethod $method) => $method->getName(), $methods);
            sort($names);
            $surface[$class] = array_values($names);
        }

        $this->assertSame(self::PUBLIC_SURFACE, $surface);
        $this->assertTrue(function_exists('t'));
    }

    public function testEveryConfigKeyIsACoreOptionOrLaravelWiring(): void
    {
        $this->assertSame(array_keys(self::CONFIG_SURFACE), self::_configKeys(require $this->_root() . '/config/langsys.php'));
    }

    /** Firing control: the comparison above sees a key the core does not define. */
    public function testTheConfigCheckSeesAnAddedKey(): void
    {
        $planted = require $this->_root() . '/config/langsys.php';
        $planted['auto_flush'] = true;

        $this->assertNotSame(array_keys(self::CONFIG_SURFACE), self::_configKeys($planted));
    }

    /** @return list<string> "file: construct" for every forbidden construct in this package's code */
    private function _scanSource(array $needles): array
    {
        $hits = [];

        foreach ([...$this->_phpFiles($this->_root() . '/src'), $this->_root() . '/config/langsys.php'] as $file) {
            foreach (self::_scan(file_get_contents($file), $needles) as $needle) {
                $hits[] = substr($file, strlen($this->_root()) + 1) . ': ' . $needle;
            }
        }

        return $hits;
    }

    /**
     * Identifiers match by name, including the last segment of a qualified
     * name. String literals match by substring only for needles with a
     * non-alphanumeric character in them (`data-ls-`, `x-write-grant`), and by
     * whole value otherwise, so `hash` does not flag a string that says "hashes".
     *
     * @return list<string>
     */
    private static function _scan(string $source, array $needles): array
    {
        $found = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            $isString = in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
            $isName = in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);

            if (!$isString && !$isName) {
                continue;
            }

            $text = strtolower($isString ? trim($text, '\'"') : ltrim($text, '\\'));

            foreach ($needles as $needle) {
                $hit = $isName
                    ? $text === $needle || str_ends_with($text, '\\' . $needle) || (str_contains($needle, '\\') && str_contains($text, $needle))
                    : (preg_match('/[^a-z0-9]/', $needle) ? str_contains($text, $needle) : $text === $needle);

                if ($hit) {
                    $found[] = $needle;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return array<string, string> each code form the needle could take in real source */
    private static function _plantings(string $needle): array
    {
        $forms = ['a string literal' => "<?php \$x = '{$needle}';"];

        if (str_contains($needle, '\\')) {
            $forms['an imported name'] = "<?php use {$needle};";
        } elseif (preg_match('/^[a-z_][a-z0-9_]*$/', $needle)) {
            $forms['a call'] = "<?php {$needle}(\$x); \$c->{$needle}();";
        }

        return $forms;
    }

    /** @return list<string> dotted leaf keys; lists and scalars are leaves */
    private static function _configKeys(array $config, string $prefix = ''): array
    {
        $keys = [];

        foreach ($config as $key => $value) {
            is_array($value) && !array_is_list($value)
                ? array_push($keys, ...self::_configKeys($value, "{$prefix}{$key}."))
                : $keys[] = "{$prefix}{$key}";
        }

        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function _phpFiles(string $dir): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function _root(): string
    {
        return dirname(__DIR__);
    }
}
