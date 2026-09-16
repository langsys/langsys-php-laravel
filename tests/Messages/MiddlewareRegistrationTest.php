<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Langsys\Laravel\Http\Middleware\AttachServerMessages;
use Langsys\Laravel\Tests\TestCase;

/**
 * The kernel throws when asked to append to a group it does not define, and an application is free
 * to have no `api` group at all. Appending blindly would take such an application down at boot,
 * in migrate mode, before anything of ours ran.
 */
class MiddlewareRegistrationTest extends TestCase
{
    private static object $kernel;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.localization', 'migrate');

        self::$kernel = new class implements HttpKernel {
            /** @var array<string, list<string>> */
            public array $groups = ['web' => []];

            public function getMiddlewareGroups(): array
            {
                return $this->groups;
            }

            public function appendMiddlewareToGroup($group, $middleware)
            {
                if (!isset($this->groups[$group])) {
                    throw new \InvalidArgumentException("The [{$group}] middleware group has not been defined.");
                }

                $this->groups[$group][] = $middleware;

                return $this;
            }

            public function bootstrap() {}

            public function handle($request) {}

            public function terminate($request, $response) {}

            public function getApplication() {}
        };

        $app->instance(HttpKernel::class, self::$kernel);
    }

    public function testOnlyGroupsTheApplicationDefinesAreAppendedTo(): void
    {
        $this->assertSame(['web' => [AttachServerMessages::class]], self::$kernel->groups);
    }
}
