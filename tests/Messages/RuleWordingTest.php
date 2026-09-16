<?php

namespace Langsys\Laravel\Tests\Messages;

use Langsys\Laravel\Messages\RuleWording;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Messages\MessageCodes;

/**
 * The wording table has to account for every rule the installed Laravel ships, and for every
 * placeholder in each rule's English line. It is read from the framework's own `validation.php`
 * rather than from a copy, so a Laravel upgrade that adds a rule or a placeholder fails here
 * instead of sending an unclassified message to a user.
 */
class RuleWordingTest extends TestCase
{
    /** Placeholders Laravel fills in any message, not only the ones its lines spell. */
    private const RUNTIME_PLACEHOLDERS = ['input', 'index', 'position', 'ordinal-position'];

    public function testEveryRuleLaravelShipsIsClassified(): void
    {
        $missing = array_values(array_filter(array_keys($this->_lines()), fn (string $rule) => RuleWording::classification($rule) === null));

        $this->assertSame([], $missing, 'Rules the installed Laravel ships that the wording table does not classify.');
    }

    public function testEveryPlaceholderInEachLineIsClassified(): void
    {
        $unclassified = [];

        foreach ($this->_lines() as $rule => $lines) {
            $placeholders = RuleWording::classification($rule)['placeholders'] ?? [];

            foreach ((array) $lines as $line) {
                preg_match_all('/:([a-z_]+)/', $line, $matches);

                foreach (array_diff(array_unique($matches[1]), array_keys($placeholders), self::RUNTIME_PLACEHOLDERS) as $placeholder) {
                    $unclassified[] = "{$rule} :{$placeholder}";
                }
            }
        }

        $this->assertSame([], $unclassified);
    }

    /** MSG-3: a label governs agreement, so it is always written in and never a marker. */
    public function testLabelsAreNeverMarkers(): void
    {
        foreach (array_keys($this->_lines()) as $rule) {
            $placeholders = RuleWording::classification($rule)['placeholders'] ?? [];

            foreach (['attribute', 'other'] as $label) {
                if (isset($placeholders[$label])) {
                    $this->assertNotSame(RuleWording::MARKER, $placeholders[$label], "{$rule} :{$label}");
                }
            }
        }
    }

    public function testEveryCodeComesFromTheSharedVocabulary(): void
    {
        foreach (array_keys($this->_lines()) as $rule) {
            foreach (RuleWording::codesFor($rule) as $code) {
                $this->assertContains($code, MessageCodes::VOCABULARY, "{$rule} → {$code}");
            }
        }
    }

    /**
     * MSG-2: a size rule's code follows the field's type, so one rule produces several codes. The
     * mapping itself is the core's (`MessageCodes::forBound`); what is pinned here is that this
     * table asks for every type a Laravel field can have, and for both sides where the rule does
     * not say which one failed.
     */
    public function testASizeRuleCoversEveryFieldTypeAndSide(): void
    {
        $this->assertSame(['too_short', 'too_small', 'too_few'], RuleWording::codesFor('min'));
        $this->assertSame(['too_long', 'too_large', 'too_many'], RuleWording::codesFor('max'));
        $this->assertSame(['too_short', 'too_small', 'too_few', 'too_long', 'too_large', 'too_many'], RuleWording::codesFor('between'));
        $this->assertSame(['too_short', 'too_long'], RuleWording::codesFor('digits_between'), 'A digit count is measured as text.');
    }

    /** @return array<string, string|array<string, string>> rule => its English line, or its size variants */
    private function _lines(): array
    {
        $lines = require dirname((new \ReflectionClass(\Illuminate\Translation\Translator::class))->getFileName()) . '/lang/en/validation.php';

        unset($lines['custom'], $lines['attributes']);

        return $lines;
    }
}
