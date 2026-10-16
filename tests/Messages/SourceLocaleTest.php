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

    /** The listing is source text, whatever the console's locale: labels, Laravel's lines and a rule object's template alike. */
    public function testTheListingIsWrittenInTheSourceLanguage(): void
    {
        $this->app->setLocale('es');

        $catalog = \Langsys\SDK\Messages\MessageCatalogCommand::collect([new \Langsys\Laravel\Messages\FormRequestSource([\Langsys\Laravel\Tests\Fixtures\Http\ProfileRequest::class])]);

        $this->assertEqualsCanonicalizing(
            ['The email field is required.', 'The selected status is invalid.', 'The notes may not be more than {max} words.'],
            array_column($catalog->templates(), 'template')
        );
        $this->assertSame('es', app()->getLocale(), "The console's locale is left as it was.");
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
    }
}
