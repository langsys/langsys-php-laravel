<?php

namespace Langsys\Laravel\Tests;

use Langsys\SDK\Client;
use Langsys\SDK\Snapshot\Snapshot;
use ReflectionMethod;

/**
 * SNAP-2: `langsys.snapshot` names a snapshot file the Client is seeded from, so a render has
 * translations with no network call. Loading, lookups and precedence are the core's; the binding
 * only reads the path from Laravel's config. A snapshot the core refuses is reported, never a 500.
 */
class SnapshotTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'snapshot');
        file_put_contents($this->path, self::_snapshot(['es-es' => ['__uncategorized__' => ['Save' => 'Guardar']]]));

        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(\Langsys\Laravel\LangsysTranslator::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /** A snapshot as the core exports it: its members and their checksum. */
    private static function _snapshot(array $catalog): string
    {
        $payload = [
            'project_id'   => 'test-project',
            'generated_at' => '2026-09-26T00:00:00Z',
            'base_locale'  => 'en-us',
            'locales'      => array_keys($catalog),
            'categories'   => ['__uncategorized__'],
            'catalog'      => $catalog,
        ];

        return json_encode(['format' => Snapshot::FORMAT, 'version' => Snapshot::VERSION] + $payload
            + ['checksum' => (new ReflectionMethod(Snapshot::class, 'checksum'))->invoke(null, $payload)]);
    }

    public function testAConfiguredSnapshotAnswersWithNoNetwork(): void
    {
        config()->set('langsys.snapshot', $this->path);

        $this->assertSame('Guardar', t('Save', [], 'es-ES'));
    }

    /** Control: without the setting, the same lookup offline renders the source phrase. */
    public function testWithoutASnapshotTheLookupFallsBackToSource(): void
    {
        $this->assertSame('Save', t('Save', [], 'es-ES'));
    }

    public function testASnapshotTheCoreRefusesIsReportedAndSkipped(): void
    {
        file_put_contents($this->path, str_replace('Guardar', 'Salvar', (string) file_get_contents($this->path)));
        config()->set('langsys.snapshot', $this->path);
        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $this->assertSame('Save', t('Save', [], 'es-ES'));
        $this->assertCount(1, $reported);
        $this->assertStringContainsString('checksum', $reported[0]->getMessage());
    }
}
