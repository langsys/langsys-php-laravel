<?php

namespace Langsys\Laravel\Tests\View;

use Langsys\Laravel\View\PlaceholderNames;
use PHPUnit\Framework\TestCase;

/**
 * VAR-2: the shared naming vectors, vendored byte for byte from langsys-js-typescript
 * (tests/fixtures/var-naming-vectors.json, blob a4b61ed2), executed row for row. One case is one
 * phrase; collisions resolve within it.
 */
class PlaceholderNamesTest extends TestCase
{
    public function testEverySharedVectorNamesAsTheFleetDoes(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/var-naming-vectors.json'), true);
        $this->assertGreaterThan(20, count($vectors['cases']), 'Control: the vectors loaded.');

        foreach ($vectors['cases'] as $case) {
            $this->assertSame($case['names'], PlaceholderNames::names($case['expressions'])['names'], $case['id'] . ': ' . $case['why']);
        }
    }

    /** Blade's PHP expressions read into the vectors' shapes. */
    public function testPhpExpressionsReadIntoTheSharedShapes(): void
    {
        foreach ([
            '$firstName'                  => ['identifier' => 'firstName'],
            '$user->name'                 => ['member' => ['user', 'name']],
            '$user?->profile->displayName' => ['member' => ['user', 'profile', 'displayName']],
            "\$order['total']"            => ['member' => ['order', 'total']],
            '$items->count()'             => ['member' => ['items', 'count']],
            'count($items)'               => ['member' => ['items', 'count']],
            'count($user->orders)'        => ['member' => ['user', 'orders', 'count']],
            'ucfirst($user->name)'        => ['call' => ['callee' => 'ucfirst', 'args' => [['member' => ['user', 'name']]]]],
            'Str::upper($city)'           => ['call' => ['callee' => 'upper', 'args' => [['identifier' => 'city']]]],
        ] as $expression => $shape) {
            $this->assertSame($shape, PlaceholderNames::shapeOf($expression), $expression);
        }

        foreach (['$items[$i]', '$a . $b', '$ok ? $x : $y', 'number_format($price, 2)'] as $unnameable) {
            $named = PlaceholderNames::names([['shape' => PlaceholderNames::shapeOf($unnameable), 'source' => $unnameable]]);
            $this->assertSame([$unnameable], $named['unnamed'], "$unnameable is unnameable");
        }

        $this->assertSame(['items_count'], PlaceholderNames::names([['shape' => PlaceholderNames::shapeOf('count($items)'), 'source' => 'count($items)']])['names']);
    }
}
