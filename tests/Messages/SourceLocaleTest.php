<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Support\Facades\Validator;
use Langsys\Laravel\Tests\TestCase;

/**
 * MSG-3: an entry's template is the source sentence, whatever language the request is in. The
 * app's labels and option words for that language (`validation.attributes`, `validation.values`)
 * are the request's, so they stay out of the template, as the line itself does.
 */
class SourceLocaleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->useLangPath(__DIR__ . '/../Fixtures/lang-validation');
        $app['config']->set('app.fallback_locale', 'en');
    }

    public function testATemplateIsWrittenInTheSourceLanguageWhateverTheRequestsIs(): void
    {
        $this->app->setLocale('es');

        $validator = Validator::make(['type' => 'business', 'notes' => 'one two'], ['email' => 'required', 'vat' => 'required_if:type,business', 'notes' => [new \Langsys\Laravel\Tests\Fixtures\Http\MaxWords(1)]]);
        $this->assertSame('El campo correo electrónico es obligatorio.', $validator->errors()->first('email'), "Control: Laravel's own message is the request's language.");

        $templates = array_map(fn ($entry) => $entry->getTemplate(), $validator->serverMessages());

        $this->assertSame(['The email field is required.', 'The vat field is required when type is business.', 'The notes may not be more than {max} words.'], $templates);
    }

    /**
     * A custom message or a label the app reads through `__()` is in the request's language by the
     * time Laravel's validator holds it; the entry is written from its source, as the listing lists
     * it — whether the catalog answered, or with no key the app's own lang file.
     */
    public function testAMessageOrLabelReadThroughTranslateIsSentFromItsSource(): void
    {
        $this->app->setLocale('es');
        $plural = \Langsys\SDK\Migration\LegacyValue::fromCall('Pick :count plan.|Pick :count plans.', [], 'trans_choice', 1)['text'];
        $this->fakeClient->seed('es', '__uncategorized__', ['We need your email.' => 'Necesitamos tu correo.', 'full name' => 'nombre completo', $plural => '{count, plural, one {Elige # plan.} other {Elige # planes.}}']);

        foreach (['a key' => fn () => null, 'no key' => function () {
            config()->set('langsys.api_key', null);
            $this->app->forgetInstance(\Langsys\SDK\Client::class);
        }] as $case => $setUp) {
            $setUp();
            // Each case is its own request, so neither reads the other's calls.
            $this->app->instance('request', \Illuminate\Http\Request::create('/'));
            $validator = Validator::make(['email' => '', 'name' => '', 'plan' => ''], ['email' => 'required', 'name' => 'required', 'plan' => 'required'], ['email.required' => __('We need your email.'), 'plan.required' => trans_choice('Pick :count plan.|Pick :count plans.', 1)], ['name' => __('full name')]);
            $validator->fails();

            $this->assertSame(['Necesitamos tu correo.', 'Elige 1 plan.'], [$validator->errors()->first('email'), $validator->errors()->first('plan')], "$case: control, the bag is the request's language.");
            $this->assertSame(['We need your email.', 'The full name field is required.', 'Pick 1 plan.'], array_map(fn ($entry) => $entry->getTemplate(), $validator->serverMessages()), $case);
        }
    }

    /** A closure's `$fail()` and an `after()` hook's message read through `__()` are sent from their source too. */
    public function testATextOnlyMessageReadThroughTranslateIsSentFromItsSource(): void
    {
        $this->app->setLocale('es');
        $this->fakeClient->seed('es', '__uncategorized__', ['Pick a valid plan.' => 'Elige un plan válido.', 'Plans are closed today.' => 'Los planes están cerrados hoy.']);

        $validator = Validator::make(['plan' => 'x'], ['plan' => [fn ($attribute, $value, $fail) => $fail(__('Pick a valid plan.'))]]);
        $validator->after(fn ($validator) => $validator->errors()->add('plan', __('Plans are closed today.')));
        $validator->fails();

        $this->assertSame(['Elige un plan válido.', 'Los planes están cerrados hoy.'], $validator->errors()->get('plan'), "Control: the bag is the request's language.");
        $this->assertSame(['Pick a valid plan.', 'Plans are closed today.'], array_map(fn ($entry) => $entry->getTemplate(), $validator->serverMessages()));

        $thrown = \Illuminate\Validation\ValidationException::withMessages(['plan' => __('Plans are closed today.')]);
        $this->assertSame(['Plans are closed today.'], array_map(fn ($entry) => $entry->getTemplate(), \Langsys\Laravel\Messages\ValidatorMessages::fromValidator($thrown->validator)), 'A failure carrying text and no rule.');
    }

    /**
     * Laravel's own rule objects print a line with the label the app read through `__()`: the line
     * is found as Laravel printed it, and sent with the source label written in. A `Password`'s
     * nested rule keeps its value as a marker, and a rule object given to it whose message names
     * the field is read again in the source language.
     */
    public function testLaravelsOwnRuleObjectsWithALabelReadThroughTranslateAreSentFromTheSource(): void
    {
        $this->app->setLocale('es');
        $this->fakeClient->seed('es', '__uncategorized__', ['state' => 'estado', 'PIN code' => 'código PIN', 'Enter a valid code.' => 'Introduzca un código válido.', 'The :attribute was used before.' => 'El :attribute ya se usó.', 'Too predictable.' => 'Demasiado predecible.']);

        $validator = Validator::make(['status' => 'nope', 'pin' => '1'], [
            'status' => [\Illuminate\Validation\Rule::enum(\Langsys\Laravel\Tests\Fixtures\app\Enums\Unmarked::class)],
            'pin'    => [\Illuminate\Validation\Rules\Password::min(2)->letters()->rules([new \Langsys\Laravel\Tests\Fixtures\Http\TranslatedCodeRule(), new \Langsys\Laravel\Tests\Fixtures\Http\UsedBeforeRule(), fn ($attribute, $value, $fail) => $fail(__('Too predictable.'))])],
        ], [], ['status' => __('state'), 'pin' => __('PIN code')]);
        $validator->fails();

        $this->assertSame('El estado seleccionado no es válido.', $validator->errors()->first('status'), "Control: the bag is the request's language.");
        $this->assertContains('El código PIN ya se usó.', $validator->errors()->get('pin'), "Control: Laravel writes the label into the translated message.");

        if (!method_exists(\Illuminate\Validation\Rules\Password::class, 'appliedRules')) {
            return;
        }

        $this->assertSame([
            ['The selected state is invalid.', []],
            ['The PIN code field must be at least {min} characters.', ['min' => 2]],
            ['Enter a valid code.', []],
            ['The PIN code was used before.', []],
            ['Too predictable.', []],
            ['The PIN code field must contain at least one letter.', []],
        ], array_map(fn ($entry) => [$entry->getTemplate(), $entry->getParams()], $validator->serverMessages()), 'Rules given to Password included.');
    }

    /** The listing is source text, whatever the console's locale: labels, Laravel's lines and a rule object's template alike. */
    public function testTheListingIsWrittenInTheSourceLanguage(): void
    {
        $this->app->setLocale('es');

        $catalog = \Langsys\SDK\Messages\MessageCatalogCommand::collect([new \Langsys\Laravel\Messages\FormRequestSource([\Langsys\Laravel\Tests\Fixtures\Http\ProfileRequest::class])]);

        $this->assertEqualsCanonicalizing(
            ['The email field is required.', 'The selected status is invalid.', 'The notes may not be more than {max} words.'],
            array_column($catalog->templates(), 'template')
        );
        $this->assertSame('es', app('translator')->getLocale(), "The console's locale is left as it was.");
    }

    /**
     * A rule object's message read through `__()` is the app's source line in the listing and in a
     * failing request's entry, though the catalog holds its translation for the request's language.
     */
    public function testARuleObjectsTranslatedMessageIsReadInTheSourceLanguage(): void
    {
        $this->app->setLocale('es');
        $this->fakeClient->seed('es', '__uncategorized__', ['Enter a valid code.' => 'Introduzca un código válido.']);

        $validator = Validator::make(['code' => 'x'], ['code' => [new \Langsys\Laravel\Tests\Fixtures\Http\TranslatedCodeRule()]]);
        $this->assertSame('Introduzca un código válido.', $validator->errors()->first('code'), "Control: the request's language reads the catalog.");
        $this->assertSame(['Enter a valid code.'], array_map(fn ($entry) => $entry->getTemplate(), $validator->serverMessages()));

        $catalog = \Langsys\SDK\Messages\MessageCatalogCommand::collect([new \Langsys\Laravel\Messages\FormRequestSource([\Langsys\Laravel\Tests\Fixtures\Http\CodeRequest::class])]);

        $this->assertSame(['Enter a valid code.'], array_column($catalog->templates(), 'template'));
    }

    /**
     * FRM-1: building a failure's entries tells the app's missing-key handler nothing — the line,
     * the label and a rule's message read in the source language are probes, not the app's calls —
     * so the handler hears what Laravel's own validator tells it, and no more.
     */
    public function testBuildingEntriesTellsTheMissingKeyHandlerNothing(): void
    {
        $this->app->setLocale('es');
        $this->fakeClient->seed('es', '__uncategorized__', ['Enter a valid code.' => 'Introduzca un código válido.', 'We need your name.' => 'Necesitamos tu nombre.']);
        $data = ['code' => 'x', 'email' => '', 'pin' => '12', 'name' => ''];
        $rules = ['code' => [new \Langsys\Laravel\Tests\Fixtures\Http\TranslatedCodeRule()], 'email' => 'required', 'pin' => [\Illuminate\Validation\Rules\Password::min(8)->letters()], 'name' => 'required'];
        $messages = ['name.required' => __('We need your name.')];
        $heard = [];

        if (!$this->missingKeyHandler(function ($key, $replace, $locale) use (&$heard) {
            $heard[] = "$locale:$key";
        })) {
            $this->markTestSkipped('This Laravel has no missing-key handler.');
        }

        (new \Illuminate\Validation\Validator($this->app['translator'], $data, $rules, $messages))->fails();
        $laravel = $heard;
        $heard = [];

        $validator = Validator::make($data, $rules, $messages);
        $validator->fails();
        $this->assertNotEmpty($validator->serverMessages(), 'Control: entries were built.');

        $this->assertSame($laravel, $heard);
    }

    /** The listing's reads are probes too: the app's missing-key handler hears nothing of them. */
    public function testTheListingTellsTheMissingKeyHandlerNothing(): void
    {
        $heard = [];

        if (!$this->missingKeyHandler(function ($key, $replace, $locale) use (&$heard) {
            $heard[] = "$locale:$key";
        })) {
            $this->markTestSkipped('This Laravel has no missing-key handler.');
        }

        $catalog = \Langsys\SDK\Messages\MessageCatalogCommand::collect([new \Langsys\Laravel\Messages\FormRequestSource([\Langsys\Laravel\Tests\Fixtures\Http\ProfileRequest::class, \Langsys\Laravel\Tests\Fixtures\Http\CodeRequest::class])]);

        $this->assertNotEmpty($catalog->templates(), 'Control: the listing read the requests.');
        $this->assertSame([], $heard);
    }

    /** A message the app declared for a rule object is Laravel's pick over the rule's own, at runtime and in the listing. */
    public function testAMessageTheAppDeclaredForARuleObjectIsItsTemplate(): void
    {
        $validator = Validator::make(['code' => 'x'], ['code' => [new \Langsys\Laravel\Tests\Fixtures\Http\TranslatedCodeRule()]], ['code.' . \Langsys\Laravel\Tests\Fixtures\Http\TranslatedCodeRule::class => 'That code is wrong.']);
        $this->assertSame('That code is wrong.', $validator->errors()->first('code'), "Control: Laravel picks the declared message.");
        $this->assertSame(['That code is wrong.'], array_map(fn ($entry) => $entry->getTemplate(), $validator->serverMessages()));

        $catalog = \Langsys\SDK\Messages\MessageCatalogCommand::collect([new \Langsys\Laravel\Messages\FormRequestSource([\Langsys\Laravel\Tests\Fixtures\Http\DeclaredCodeRequest::class])]);

        $this->assertSame(['That code is wrong.'], array_column($catalog->templates(), 'template'));
        $this->assertSame([], $catalog->problems(), 'A declared message is the app\'s own template, with nothing filled into it.');
    }

    /**
     * Laravel's own rule objects state Laravel's own lines: the line the request's language filled
     * is written in the source language, as the listing lists it.
     */
    public function testLaravelsOwnRuleObjectsAreWrittenInTheSourceLanguage(): void
    {
        $this->app->setLocale('es');

        $validator = Validator::make(['status' => 'nope', 'pin' => '1'], [
            'status' => [\Illuminate\Validation\Rule::enum(\Langsys\Laravel\Tests\Fixtures\app\Enums\Unmarked::class)],
            'pin'    => [\Illuminate\Validation\Rules\Password::min(1)->letters()],
        ]);
        $this->assertSame('El status seleccionado no es válido.', $validator->errors()->first('status'), "Control: Laravel's own message is the request's language.");

        $templates = array_map(fn ($entry) => $entry->getTemplate(), $validator->serverMessages());

        $this->assertSame(['The selected status is invalid.', 'The pin field must contain at least one letter.'], $templates);

        if (method_exists(\Illuminate\Validation\Rules\Password::class, 'appliedRules')) {
            $short = Validator::make(['pin' => '12'], ['pin' => [\Illuminate\Validation\Rules\Password::min(8)->letters()]]);
            $short->fails();

            $this->assertSame(['The pin field must be at least {min} characters.', 'The pin field must contain at least one letter.'], array_map(fn ($entry) => $entry->getTemplate(), $short->serverMessages()), "A Password's nested rule is sent in the source language too, its value a marker.");
        }
    }
}
