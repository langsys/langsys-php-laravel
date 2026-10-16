<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Langsys\Laravel\Messages\MessageValidator;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;
use RuntimeException;

/**
 * Migrate mode adds structure and registration to a failure; it does not change a word Laravel
 * renders. The server registers source phrases, the API translates them, and the client renders
 * `entry.template` (MSG-5). So nothing translated by Langsys may leave the server as part of this
 * feature: not the message bag, not the 422 body, not an entry's `message`.
 */
class MigrateModeTest extends TestCase
{
    private const TEMPLATE = 'The card number field is required.';
    private const SPANISH = 'El número de tarjeta es obligatorio.';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->post('/api/cards', fn () => request()->validate(['cc_number' => 'required'], [], ['cc_number' => 'card number']));
    }

    private function _failing(): LaravelValidator
    {
        $validator = Validator::make([], ['cc_number' => 'required'], [], ['cc_number' => 'card number']);
        $validator->fails();

        return $validator;
    }

    public function testLaravelBuildsThePackagesValidator(): void
    {
        $this->assertInstanceOf(MessageValidator::class, Validator::make([], ['name' => 'required']));
    }

    /**
     * The load-bearing rule: even with the sentence translated in the catalog and the request in
     * that locale, what leaves the server is the source text.
     */
    public function testTheServerNeverEmitsTranslatedText(): void
    {
        $this->app->instance(Client::class, $this->offlineClient(['es-es' => ['Errors' => [self::TEMPLATE => self::SPANISH]]]));
        $this->app->setLocale('es-ES');

        $validator = $this->_failing();

        $this->assertSame(self::TEMPLATE, $validator->errors()->first('cc_number'), 'The message bag, and so the 422 errors map.');
        $this->assertSame(self::TEMPLATE, $validator->serverMessages()[0]->getMessage(), "The entry's message is the base-locale fill.");
    }

    /** The entries themselves are kept for the response envelope (MSG-1). */
    public function testTheEntriesAreKeptOnTheValidator(): void
    {
        $this->app->instance(Client::class, $this->offlineClient(['es-es' => ['Errors' => ['Another sentence.' => 'Otra frase.']]]));

        $entries = $this->_failing()->serverMessages();

        $this->assertCount(1, $entries);
        $this->assertSame('required', $entries[0]->getCode());
        $this->assertSame(self::TEMPLATE, $entries[0]->getTemplate());
        $this->assertSame('cc_number', $entries[0]->getField());
    }

    /**
     * FRM-2: a failed validation registers nothing at runtime, nor does the JSON response that
     * carries its entries; `langsys:sync` registers templates.
     */
    public function testAFailureRegistersNothingAtRuntime(): void
    {
        // As the provider builds it (`runtime_registration` off), for a project that serves Spanish.
        $client = $this->offlineClient(['es-es' => ['Errors' => ['Another sentence.' => 'Otra frase.']]], ['runtime_registration' => false]);
        $client->serveProject(['base_locale' => 'en-us', 'target_locales' => ['es-es']]);
        $this->app->instance(Client::class, $client);
        $this->app->setLocale('es-ES');

        $this->_failing();
        $this->postJson('/api/cards', [], ['Accept-Language' => 'es'])->assertStatus(422)->assertHeader('Content-Language', 'es-es');

        $this->assertSame([], $client->getPendingPhrases());
    }

    /** MSG-6: the configured category is the core's messages category, where templates are looked up and synced. */
    public function testTheConfiguredCategoryIsTheCoresMessagesCategory(): void
    {
        config()->set('langsys.messages.category', 'Validation');
        $this->app->forgetInstance(Client::class);

        $this->assertSame('Validation', $this->app->make(Client::class)->getConfig()->getMessagesCategory());
    }

    /** A translation layer must never be the reason a form breaks. */
    public function testAFailingClientLeavesLaravelsMessages(): void
    {
        $this->app->bind(Client::class, fn () => throw new RuntimeException('no credentials'));

        $this->assertSame(self::TEMPLATE, $this->_failing()->errors()->first('cc_number'));
    }
}
