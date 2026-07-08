<?php

namespace Langsys\Laravel\Tests;

use DateTimeImmutable;
use Langsys\Laravel\Interpolator;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

class InterpolatorTest extends PhpUnitTestCase
{
    private Interpolator $interpolator;

    protected function setUp(): void
    {
        $this->interpolator = new Interpolator();
    }

    public function testSubstitutesSimplePlaceholders(): void
    {
        $result = $this->interpolator->interpolate('Hello, {name}!', ['name' => 'Sarah']);

        $this->assertSame('Hello, Sarah!', $result);
    }

    public function testLeavesUnknownPlaceholdersUntouched(): void
    {
        $result = $this->interpolator->interpolate('Hello, {name}! You have {count} messages.', ['name' => 'Sarah']);

        $this->assertSame('Hello, Sarah! You have {count} messages.', $result);
    }

    public function testLeavesNullParamsUntouched(): void
    {
        $result = $this->interpolator->interpolate('Hello, {name}!', ['name' => null]);

        $this->assertSame('Hello, {name}!', $result);
    }

    public function testTrimsPlaceholderWhitespace(): void
    {
        $result = $this->interpolator->interpolate('Hello, { name }!', ['name' => 'Sarah']);

        $this->assertSame('Hello, Sarah!', $result);
    }

    public function testFormatsNumbersForTheTargetLocale(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $result = $this->interpolator->interpolate('{amount} items', ['amount' => 1234.5], 'de-DE');

        $this->assertSame('1.234,5 items', $result);
    }

    public function testStringNumbersOptOutOfFormatting(): void
    {
        $result = $this->interpolator->interpolate('Order {id}', ['id' => '1234567'], 'de-DE');

        $this->assertSame('Order 1234567', $result);
    }

    public function testFormatsDatesForTheTargetLocale(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $date = new DateTimeImmutable('2026-07-07');
        $result = $this->interpolator->interpolate('Due {when}', ['when' => $date], 'en-US');

        $this->assertStringContainsString('2026', $result);
        $this->assertStringContainsString('Jul', $result);
    }

    public function testRendersBooleansLikeTheJsSdk(): void
    {
        $result = $this->interpolator->interpolate('Active: {flag}', ['flag' => true]);

        $this->assertSame('Active: true', $result);
    }

    public function testIcuPluralSelectsTheRightBranch(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $template = '{count, plural, one {# item} other {# items}}';

        $this->assertSame('1 item', $this->interpolator->interpolate($template, ['count' => 1], 'en'));
        $this->assertSame('3 items', $this->interpolator->interpolate($template, ['count' => 3], 'en'));
    }

    public function testMalformedIcuFallsBackToSimpleInterpolation(): void
    {
        $result = $this->interpolator->interpolate('{count, plural, broken {name}', ['name' => 'x'], 'en');

        $this->assertStringContainsString('x', $result);
    }

    public function testIsIcuDetectsIcuButNotPlainSlots(): void
    {
        $this->assertTrue($this->interpolator->isIcu('{n, plural, one {#} other {#}}'));
        $this->assertTrue($this->interpolator->isIcu('{n, number}'));
        $this->assertFalse($this->interpolator->isIcu('Hello, {name}!'));
    }
}
