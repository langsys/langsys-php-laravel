<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Langsys\Laravel\Messages\ValidatorMessages;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Messages\MessageTemplate;

/**
 * MSG-9 and MSG-10: entries are built from the rules that failed and their parameters, never by
 * parsing the message Laravel rendered. The label comes from Laravel's own label concept and is
 * written into the sentence (MSG-3); a value that cannot be translated stays outside it as a
 * `{name}` marker (MSG-11).
 *
 * The load-bearing test is the equivalence one: filling a template with its params has to
 * reproduce Laravel's own message exactly. That is what keeps the wording Laravel's, and what
 * would catch a rule this package words differently from the framework.
 */
class ValidatorMessagesTest extends TestCase
{
    /** @return array<string, array{rules: array, data: array, attributes: array, field: string}> */
    private function _cases(): array
    {
        return [
            'required'                 => ['rules' => ['cc_number' => 'required'], 'data' => [], 'attributes' => ['cc_number' => 'card number'], 'field' => 'cc_number'],
            'min on text'              => ['rules' => ['password' => 'string|min:8'], 'data' => ['password' => 'short'], 'attributes' => [], 'field' => 'password'],
            'min on a number'          => ['rules' => ['age' => 'numeric|min:18'], 'data' => ['age' => 12], 'attributes' => [], 'field' => 'age'],
            'between on a list'        => ['rules' => ['tags' => 'array|between:1,3'], 'data' => ['tags' => ['a', 'b', 'c', 'd']], 'attributes' => [], 'field' => 'tags'],
            'same as another field'    => ['rules' => ['password' => 'same:password_confirmation'], 'data' => ['password' => 'a', 'password_confirmation' => 'b'], 'attributes' => ['password_confirmation' => 'password confirmation'], 'field' => 'password'],
            'required_if on a value'   => ['rules' => ['vat_id' => 'required_if:type,business'], 'data' => ['type' => 'business'], 'attributes' => ['vat_id' => 'VAT number'], 'field' => 'vat_id'],
            'before a literal date'    => ['rules' => ['born_on' => 'date|before:2020-01-01'], 'data' => ['born_on' => '2024-05-05'], 'attributes' => [], 'field' => 'born_on'],
            'before another field'     => ['rules' => ['starts_at' => 'date|before:ends_at'], 'data' => ['starts_at' => '2024-05-05', 'ends_at' => '2024-01-01'], 'attributes' => ['ends_at' => 'end date'], 'field' => 'starts_at'],
            'a list of file types'     => ['rules' => ['avatar' => 'mimes:pdf,png'], 'data' => ['avatar' => 'x'], 'attributes' => [], 'field' => 'avatar'],
            'digits'                   => ['rules' => ['pin' => 'digits:4'], 'data' => ['pin' => '123'], 'attributes' => [], 'field' => 'pin'],
            'required_with'            => ['rules' => ['city' => 'required_with:street,zip'], 'data' => ['street' => 'Main'], 'attributes' => ['street' => 'street name'], 'field' => 'city'],
            'in'                       => ['rules' => ['status' => 'in:draft,published'], 'data' => ['status' => 'archived'], 'attributes' => [], 'field' => 'status'],
        ];
    }

    private function _entry(array $case): \Langsys\SDK\Messages\ServerMessage
    {
        $validator = Validator::make($case['data'], $case['rules'], [], $case['attributes']);
        $this->assertTrue($validator->fails(), 'The case must fail, or it proves nothing.');

        $entries = ValidatorMessages::fromValidator($validator, 'en');
        $this->assertNotSame([], $entries);

        return $entries[0];
    }

    /**
     * The template is Laravel's sentence: filling it with its own params gives back, byte for byte,
     * the message Laravel rendered for that failure.
     */
    public function testAFilledTemplateReproducesLaravelsOwnMessage(): void
    {
        foreach ($this->_cases() as $name => $case) {
            $validator = Validator::make($case['data'], $case['rules'], [], $case['attributes']);
            $validator->fails();

            $entry = ValidatorMessages::fromValidator($validator, 'en')[0];

            $this->assertSame(
                $validator->errors()->first($case['field']),
                MessageTemplate::fill($entry->getTemplate(), $entry->getParams()),
                "Case: {$name}"
            );
        }
    }

    /** MSG-3: the label is part of the sentence, never a marker, and no Laravel placeholder survives. */
    public function testLabelsAreWrittenInAndNoPlaceholderSurvives(): void
    {
        foreach ($this->_cases() as $name => $case) {
            $template = $this->_entry($case)->getTemplate();

            $this->assertDoesNotMatchRegularExpression('/(?<![\w:]):[a-z][a-z_]*/', $template, "Case: {$name}");
        }

        $this->assertStringContainsString('card number', $this->_entry($this->_cases()['required'])->getTemplate());
        $this->assertStringContainsString('password confirmation', $this->_entry($this->_cases()['same as another field'])->getTemplate());
        $this->assertStringContainsString('end date', $this->_entry($this->_cases()['before another field'])->getTemplate());
    }

    /** MSG-11 and MSG-4: a value that cannot be translated stays outside the phrase, and a number stays a number. */
    public function testValuesStayOutsideThePhraseAsMarkers(): void
    {
        $entry = $this->_entry($this->_cases()['min on text']);

        $this->assertSame('The password field must be at least {min} characters.', $entry->getTemplate());
        $this->assertSame(['min' => 8], $entry->getParams());
        $this->assertIsInt($entry->getParams()['min']);

        $this->assertSame('The avatar field must be a file of type: {values}.', $this->_entry($this->_cases()['a list of file types'])->getTemplate());
        $this->assertSame('The born on field must be a date before {date}.', $this->_entry($this->_cases()['before a literal date'])->getTemplate());
    }

    /** MSG-2: the code comes from the rule and the field's type, not from the text. */
    public function testTheCodeComesFromTheRuleAndTheFieldType(): void
    {
        $this->assertSame('too_short', $this->_entry($this->_cases()['min on text'])->getCode());
        $this->assertSame('too_small', $this->_entry($this->_cases()['min on a number'])->getCode());
        $this->assertSame('too_many', $this->_entry($this->_cases()['between on a list'])->getCode());
        $this->assertSame('required', $this->_entry($this->_cases()['required'])->getCode());
        $this->assertSame('mismatch', $this->_entry($this->_cases()['same as another field'])->getCode());
        $this->assertSame('invalid_option', $this->_entry($this->_cases()['in'])->getCode());
    }

    /** One entry per failed rule, in order, each carrying its own field. */
    public function testOneEntryPerFailedRule(): void
    {
        $validator = Validator::make(['email' => 'no'], ['email' => 'email|min:5', 'name' => 'required']);
        $validator->fails();

        $entries = ValidatorMessages::fromValidator($validator, 'en');

        $this->assertSame(['invalid_format', 'too_short', 'required'], array_map(fn ($entry) => $entry->getCode(), $entries));
        $this->assertSame(['email', 'email', 'name'], array_map(fn ($entry) => $entry->getField(), $entries));
    }

    /**
     * The template is the source sentence, so it is read in the language the templates are written
     * in — not in the language of the request. An application in fill mode has lang files for the
     * request locale, and translating those would register a Spanish sentence as source text.
     */
    public function testTheSentenceIsReadInTheSourceLanguageNotTheRequestLocale(): void
    {
        app()->setLocale('es');
        Lang::addLines(['validation.required' => 'El campo :attribute es obligatorio.'], 'es');

        $validator = Validator::make([], ['cc_number' => 'required'], [], ['cc_number' => 'card number']);
        $validator->fails();

        $this->assertSame('El campo card number es obligatorio.', $validator->errors()->first('cc_number'), 'Control: Laravel itself renders the Spanish line.');
        $this->assertSame('The card number field is required.', ValidatorMessages::fromValidator($validator, 'en')[0]->getTemplate());
    }

    /** MSG-9: a failure that arrives with text and no rule keeps the text as its template, under `invalid`. */
    public function testATextOnlyFailureBecomesInvalid(): void
    {
        $exception = ValidationException::withMessages(['token' => 'This link has expired.']);

        $entries = ValidatorMessages::fromValidator($exception->validator, 'en');

        $this->assertCount(1, $entries);
        $this->assertSame('invalid', $entries[0]->getCode());
        $this->assertSame('This link has expired.', $entries[0]->getTemplate());
        $this->assertSame('token', $entries[0]->getField());
    }
}
