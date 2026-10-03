<?php

namespace Langsys\Laravel\Tests\Contract;

use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;

/**
 * Tests against the shared contract fixture (CONF-2): the Langsys API double in
 * tests/contract-fixture/, vendored byte for byte from langsys-js-typescript at tree
 * d7f89b89f911a90a06fc511ac72f8e0e913d4af3. It can refuse a request and it holds state, so these
 * tests assert on what the server accepted — the state read back — never on what the SDK sent.
 *
 * The Client under test is the one this package's provider builds from Laravel's config, over
 * real HTTP to the double, through the Laravel cache adapter: the route an application takes. One
 * double serves a test class; every test starts from an empty state.
 */
abstract class ContractTestCase extends TestCase
{
    protected const PROJECT = 'p1';

    /** @var resource|null */
    private static $process;

    private static array $pipes = [];

    private static ?string $apiUrl = null;

    private static ?string $fixtureUrl = null;

    private static ?string $unavailable = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$unavailable = null;
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            self::$unavailable = 'Node is not installed; the contract fixture needs Node 18 or later';

            return;
        }

        self::$process = proc_open([$node, dirname(__DIR__) . '/contract-fixture/server.mjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], self::$pipes);

        $read = [self::$pipes[1]];
        $none = null;
        $line = is_resource(self::$process) && stream_select($read, $none, $none, 10) ? fgets(self::$pipes[1]) : false;
        $ready = $line === false ? null : json_decode($line, true);

        if (!is_array($ready) || empty($ready['ready'])) {
            self::$unavailable = 'The contract fixture printed no ready line: ' . var_export($line, true);

            return;
        }

        self::$apiUrl = $ready['base_url'];
        self::$fixtureUrl = $ready['fixture_url'];
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        self::$process = null;
        parent::tearDownAfterClass();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.api_url', self::$apiUrl ?? self::UNREACHABLE_API);
        $app['config']->set('langsys.project_id', self::PROJECT);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$unavailable !== null) {
            $this->markTestSkipped(self::$unavailable);
        }

        $this->fixture('POST', '/reset');

        // The Client the provider builds, not the test double TestCase binds.
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(\Langsys\Laravel\LangsysTranslator::class);
    }

    /** Use this key for every Client built from now on, as a new process would. */
    protected function useKey(string $key): void
    {
        config()->set('langsys.api_key', $key);
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(\Langsys\Laravel\LangsysTranslator::class);
    }

    /**
     * One project (base en-us, target es-es unless given), the keys by type, and its catalog.
     *
     * @param  array<string, array>  $keys  key => [type, extra key fields]
     */
    protected function seedProject(array $keys, array $project = [], array $config = []): void
    {
        $seed = [
            'projects' => [array_merge(['id' => self::PROJECT, 'base_locale' => 'en-us', 'target_locales' => ['es-es']], $project)],
            'keys'     => array_map(fn (string $key, array $fields) => array_merge(['key' => $key, 'project' => self::PROJECT], $fields), array_keys($keys), $keys),
        ];

        if ($config !== []) {
            $seed['config'] = $config;
        }

        $this->fixture('POST', '/seed', $seed);
    }

    /** Accepted state: [category|null, phrase] pairs. */
    protected function registeredPhrases(): array
    {
        $state = $this->fixture('GET', '/state');

        return array_map(fn (array $phrase) => [$phrase['category'], $phrase['phrase']], $state['projects'][self::PROJECT]['phrases'] ?? []);
    }

    /**
     * Accepted state: the translations the server stored on each phrase, keyed "category|phrase"
     * (category '' for none).
     *
     * @return array<string, array<string, string>>
     */
    protected function storedTranslations(): array
    {
        $stored = [];

        foreach ($this->fixture('GET', '/state')['projects'][self::PROJECT]['phrases'] ?? [] as $phrase) {
            $stored[($phrase['category'] ?? '') . '|' . $phrase['phrase']] = $phrase['translations'] ?? [];
        }

        return $stored;
    }

    private function fixture(string $method, string $path, ?array $body = null): ?array
    {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body === null ? '' : json_encode($body),
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);

        $response = file_get_contents(self::$fixtureUrl . $path, false, $context);
        $status = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int) $m[1] : 0;

        if ($status < 200 || $status >= 300) {
            $this->fail("fixture $method $path answered $status: $response");
        }

        return json_decode((string) $response, true);
    }
}
