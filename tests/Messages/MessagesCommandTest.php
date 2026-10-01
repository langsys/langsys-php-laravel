<?php

namespace Langsys\Laravel\Tests\Messages;

use Langsys\Laravel\Messages\FormRequestSource;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\Laravel\Tests\Fixtures\Http\FormController;
use Langsys\Laravel\Tests\TestCase;
use Langsys\SDK\Client;
use Langsys\SDK\Messages\MessageCatalog;
use Langsys\SDK\Messages\MessageCatalogCommand;

/**
 * MSG-7: `php artisan langsys:messages` lists every validation message the application's
 * FormRequests can send, from the code, so each can be registered before anyone sees it. A listed
 * template is built by the same code as the entry a failing request emits, so the two agree.
 */
class MessagesCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Spatie\LaravelData\LaravelDataServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->post('/pay', [FormController::class, 'pay']);
        $router->post('/odd', [FormController::class, 'odd']);
        $router->post('/teams/{team}', [FormController::class, 'team']);
        $router->post('/orders', [FormController::class, 'order']);
        $router->get('/closure', fn () => 'no request class');
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Validator::extend('phone_number', fn ($attribute, $value) => is_string($value));
    }

    private function _catalog(): MessageCatalog
    {
        return MessageCatalogCommand::collect([FormRequestSource::fromRoutes($this->app['router'])]);
    }

    private function _templates(): array
    {
        return array_column($this->_catalog()->templates(), 'template');
    }

    public function testEveryRuleOfARoutesFormRequestIsListedWithItsLabelWrittenIn(): void
    {
        $templates = $this->_templates();

        foreach ([
            'The card number field is required.',
            'The card number field must be between {min} and {max} digits.',
            'The holder field is required.',
            'The holder field must be a string.',
            'The type field is required.',
            'The selected type is invalid.',
            'The VAT number field is required when type is business.',
            'The item code field is required.',
            'The item code field must be a string.',
        ] as $template) {
            $this->assertContains($template, $templates);
        }
    }

    /**
     * A laravel-data request DTO is listed as a FormRequest is: each rule of each field, the
     * DTO's own labels written in, a nested data object's fields under their own labels.
     */
    public function testALaravelDataRequestIsListedLikeAFormRequest(): void
    {
        $templates = $this->_templates();

        foreach ([
            'The customer name field is required.',
            'The customer name field must not be greater than {max} characters.',
            'The ZIP code field is required.',
        ] as $template) {
            $this->assertContains($template, $templates);
        }
    }

    /** A rule object that declares its message (Laravel's `Rule` contract) is listed once per field, the label written in. */
    public function testARuleObjectWithAMessageIsListedForEachField(): void
    {
        $this->assertContains('The coupon code must be uppercase.', $this->_templates());
    }

    /**
     * FRM-2: a rule object that states its template is listed from it, once per field, the value a
     * marker — never the number its filled message carries.
     */
    public function testARuleObjectWithATemplateIsListedWithItsMarkers(): void
    {
        $templates = $this->_templates();

        $this->assertContains('The payment reference may not be more than {max} words.', $templates);
        $this->assertNotContains('The payment reference may not be more than 3 words.', $templates);
    }

    /** One without a template is listed from its filled message, and the listing names the interface. */
    public function testARuleObjectWithoutATemplateIsNamedWithTheInterface(): void
    {
        $this->assertContains('The coupon code must be uppercase.', $this->_templates());

        $problems = implode("\n", $this->_catalog()->problems());
        $this->assertStringContainsString('StoreOrderData.coupon: is a rule object without a template', $problems);
        $this->assertStringContainsString(\Langsys\SDK\Messages\HasMessageTemplate::class, $problems);
        $this->assertStringNotContainsString('reference: is a rule object without a template', $problems, 'Control: a rule with a template is not named.');
    }

    /**
     * Laravel's own rule objects are listed from Laravel's line, which the app cannot give a
     * template: `Rules\Enum`, which laravel-data infers for an enum property, reads its line
     * through the validator it is handed.
     */
    public function testLaravelsOwnRuleObjectsAreListedFromLaravelsLine(): void
    {
        $this->assertContains('The selected order status is invalid.', $this->_templates());
        $this->assertStringNotContainsString('StoreOrderData.status', implode("\n", $this->_catalog()->problems()));
    }

    /** A rule that throws while being listed is reported, and the listing carries on past it. */
    public function testARuleThatThrowsIsReportedAndTheListingContinues(): void
    {
        $problems = implode("\n", $this->_catalog()->problems());

        $this->assertStringContainsString('UnlistableRequest.broken: cannot be listed: needs the request', $problems);
        $this->assertStringContainsString('UnlistableRequest.code: uses the rule object', $problems, 'The next field in the class is listed.');
        $this->assertContains('The card number field is required.', $this->_templates(), 'The other classes are listed.');
    }

    /** An application's own message is its template, with the label written in and values as markers. */
    public function testACustomMessageIsListedAsTheAppWroteIt(): void
    {
        $this->assertContains('Keep the holder under {max} letters.', $this->_templates());
        $this->assertNotContains('The holder field must not be greater than {max} characters.', $this->_templates());
    }

    /**
     * The point of the listing: what a failing request sends is what was registered ahead of it.
     * Every entry the runtime builds for this request is in the list.
     */
    public function testWhatARequestSendsIsWhatWasListed(): void
    {
        $response = $this->postJson('/pay', [
            'cc_number' => '12',
            'holder'    => str_repeat('x', 81),
            'type'      => 'business',
            'items'     => [['sku' => ''], ['sku' => 5]],
            'reference' => 'one two three four',
        ]);

        $sent = array_column($response->json('langsys_errors'), 'template');
        $this->assertNotEmpty($sent, 'Control: the request must fail.');
        $this->assertContains('The payment reference may not be more than {max} words.', $sent);
        $this->assertSame([], array_values(array_diff($sent, $this->_templates())));
    }

    public function testWhatCannotBeListedIsNamedWithItsFix(): void
    {
        $problems = implode("\n", $this->_catalog()->problems());

        $this->assertStringContainsString('UnlistableRequest.code: uses the rule object', $problems, 'A rule object declares no template.');
        $this->assertStringContainsString('UnlistableRequest.lines.*.qty: has no label, so each index', $problems, 'A wildcard field with no label is a template per index.');
        $this->assertStringContainsString(':thing', $problems, "The core refuses a placeholder Laravel never filled (MSG-11).");
        $this->assertStringContainsString('RouteBoundRequest', $problems, 'Rules that need the request cannot be listed without one.');
        $this->assertStringContainsString('StorePaymentRequest.pin: uses the rule object', $problems, 'Password is a rule object.');
        $this->assertStringContainsString("UnlistableRequest.contact: uses the rule 'phone_number'", $problems, 'An extension rule has no line of Laravel\'s.');
        $this->assertStringNotContainsString("'nullable'", $problems, 'A rule that never fails on its own has nothing to list.');
    }

    /** In migrate mode the listing also names what the lang files cannot carry as they stand (MIG-4). */
    public function testMigrateModeListsTheLangFilesProblems(): void
    {
        $this->app->useLangPath(__DIR__ . '/../Fixtures/lang');
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);

        $this->artisan('langsys:messages')->expectsOutputToContain('messages.shout')->assertExitCode(0);
    }

    /** MSG-10: a field with no declared label is named, with the name Laravel prints for it instead. */
    public function testAFieldWithNoDeclaredLabelIsNamed(): void
    {
        $advice = implode("\n", $this->_catalog()->advice());

        $this->assertStringContainsString('StorePaymentRequest.holder: has no declared label, so Laravel prints "holder"', $advice);
        $this->assertStringNotContainsString('StorePaymentRequest.cc_number: has no declared label', $advice);
        $this->assertStringNotContainsString('has no declared label', implode("\n", $this->_catalog()->problems()), 'Advice, not a problem.');
    }

    /** MSG-10: the listing names the unlabelled field and passes, `--strict` included. */
    public function testAFieldWithNoDeclaredLabelNeverFailsTheBuild(): void
    {
        $this->app['router']->setRoutes(new \Illuminate\Routing\RouteCollection());
        $this->app['router']->post('/devices', [FormController::class, 'device']);

        $this->artisan('langsys:messages', ['--strict' => true])
            ->expectsOutputToContain('UnlabelledRequest.device_name: has no declared label, so Laravel prints "device name"')
            ->assertExitCode(0);
    }

    /** MSG-7: problems are reported and the build passes; `--strict` fails it. */
    public function testTheCommandReportsByDefaultAndFailsUnderStrict(): void
    {
        $this->artisan('langsys:messages')
            ->expectsOutputToContain('message templates')
            ->expectsOutputToContain('UnlistableRequest.code')
            ->assertExitCode(0);

        $this->artisan('langsys:messages', ['--strict' => true])->assertExitCode(1);
    }

    public function testVerboseListsEachTemplate(): void
    {
        $this->artisan('langsys:messages', ['-v' => true])
            ->expectsOutputToContain('The card number field is required.')
            ->assertExitCode(0);
    }

    public function testRegisterSendsWhatTheCatalogLacks(): void
    {
        $client = new class extends FakeClient {
            public array $registered = [];

            public function canWrite()
            {
                return true;
            }

            public function registerPhrases(array $phrases)
            {
                $this->registered = array_merge($this->registered, $phrases);

                return ['success' => true];
            }
        };
        $client->setLocale('en');
        $client->seed('en', 'Errors', ['The card number field is required.' => null]);
        $this->app->instance(Client::class, $client);

        $this->artisan('langsys:messages', ['--register' => true])->assertExitCode(0);

        $registered = array_column($client->registered, 'phrase');
        $this->assertContains('The holder field is required.', $registered);
        $this->assertNotContains('The card number field is required.', $registered, 'Already in the catalog.');
        $this->assertSame(['Errors'], array_values(array_unique(array_column($client->registered, 'category'))));
    }
}
