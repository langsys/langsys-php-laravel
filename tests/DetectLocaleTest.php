<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Validator;
use Langsys\Laravel\Support\InertiaSsrProps;
use Langsys\Laravel\Tests\Fakes\FakeClient;
use Langsys\Laravel\Tests\Fixtures\SetsAppLocale;
use Langsys\SDK\Client;
use RuntimeException;

/**
 * SRV-6 under Laravel's convention. The locale is Laravel's: where the application has already set
 * it, that locale is served, mapped to the project's form, and DetectLocale adds nothing. Only where
 * nothing has set it this request does DetectLocale resolve it, from the configured sources in
 * order, each candidate validated against the locales the project serves, with the `Vary` that
 * choice requires. Only the project's locales, which the SDK reads from authorization, are stubbed.
 */
class DetectLocaleTest extends TestCase
{
    private const SERVED = [
        'base_locale'     => 'en-us',
        'target_locales'  => ['es-es', 'es-mx', 'fr-fr', 'de-de', 'pt-br', 'it-it'],
        'default_locales' => ['es' => 'es-mx'],
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->useLangPath(__DIR__ . '/Fixtures/lang-unserved');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/probe', fn () => response()->json([
            'app_locale'    => app()->getLocale(),
            'client_locale' => $this->app->make(Client::class)->getLocale(),
        ]));
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/save', fn () => response()->json(['text' => __('Save')]));
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/page', fn () => '<html><body><p>' . e(__('Save')) . '</p></body></html>');
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale', 'langsys.translate-page'])->get('/walked', fn () => '<html><body><p>Save</p></body></html>');
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/notify', function () {
            \Langsys\Laravel\Tests\Fixtures\Notifications\RecordingChannel::$sent = [];
            (new \Langsys\Laravel\Tests\Fixtures\Notifications\Recipient('Ana', 'fr-FR'))->notifyNow(new \Langsys\Laravel\Tests\Fixtures\Notifications\SaveNotification());

            return response()->json(['sent' => \Langsys\Laravel\Tests\Fixtures\Notifications\RecordingChannel::$sent]);
        });
        $router->middleware(['api', SetsAppLocale::class, 'langsys.locale'])->get('/api/denied', fn () => abort(403, __('Not yours.')));
        $router->middleware(['api', SetsAppLocale::class, 'langsys.locale'])->get('/api/denied-here', fn () => abort(403, __('Not yours.', [], '')));
        $router->middleware(['api', 'langsys.locale'])->post('/api/cards', fn () => request()->validate(['cc_number' => 'required']));
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/seed', fn () => response()->json(InertiaSsrProps::share()));
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/validated', function () {
            Validator::make([], ['name' => 'required'])->fails();

            return response()->json(['client_locale' => $this->app->make(Client::class)->getLocale()]);
        });
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/validated-after-switch', function () {
            app()->setLocale('fr');
            Validator::make([], ['name' => 'required'])->fails();

            return response()->json(['client_locale' => $this->app->make(Client::class)->getLocale()]);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableCookieEncryption();
        $this->_serve(self::SERVED);
    }

    private function _serve(?array $project): void
    {
        $client = new class ($project) extends FakeClient {
            public function __construct(private readonly ?array $project)
            {
                parent::__construct();
            }

            public function authorize($force = false)
            {
                return $this->project ?? throw new RuntimeException('API unreachable');
            }
        };

        $this->fakeClient = $client;
        $this->app->instance(Client::class, $client);
    }

    private function _vary($response): array
    {
        return $response->headers->all('vary');
    }

    /** The application chose the locale: Laravel keeps it, the SDK is told it, and nothing here overrides or varies. */
    public function testALocaleTheAppResolvedIsServedAsItIs(): void
    {
        $response = $this->withUnencryptedCookie('langsys_locale', 'it-IT')
            ->get('/probe?locale=es-ES', ['X-App-Locale' => 'fr-FR', 'Accept-Language' => 'de-DE']);

        $response->assertJson(['app_locale' => 'fr-FR', 'client_locale' => 'fr-fr']);
        $this->assertSame([], $this->_vary($response));
        $this->assertNull($response->getCookie('langsys_locale', false));
    }

    /** Setting the default locale explicitly is still the app's choice, and it stands over the query string. */
    public function testTheAppSettingItsDefaultLocaleCountsAsResolved(): void
    {
        $response = $this->get('/probe?locale=es-ES', ['X-App-Locale' => 'en']);

        $response->assertJson(['app_locale' => 'en', 'client_locale' => 'en-us']);
        $this->assertNull($response->getCookie('langsys_locale', false));
    }

    /** A bare language the app set maps to the project's default locale for that language (WIRE-3). */
    public function testALocaleTheAppResolvedIsMappedToTheProjectsForm(): void
    {
        $this->get('/probe', ['X-App-Locale' => 'es'])->assertJson(['app_locale' => 'es', 'client_locale' => 'es-mx']);
        $this->get('/probe', ['X-App-Locale' => 'es_ES'])->assertJson(['client_locale' => 'es-es']);
    }

    /** What the app set is served in the project's form everywhere, not only on the client: a bare language reads the project's default for it. */
    public function testTheMappedLocaleIsTheOneLookedUp(): void
    {
        $this->fakeClient->seed('es-mx', '__uncategorized__', ['Save' => 'Guardar (MX)']);

        $this->get('/save', ['X-App-Locale' => 'es'])->assertJson(['text' => 'Guardar (MX)']);
    }

    /**
     * FRM-1: a locale the project does not serve is served the base catalog, and on a miss the
     * app's own line for the locale it set is printed, exactly as Laravel prints it without Langsys.
     */
    public function testAnUnservedLocaleStillPrintsTheAppsOwnLine(): void
    {
        $this->_providersClient();
        $this->seedCatalog('en-us', ['__uncategorized__' => []]);

        $this->get('/probe', ['X-App-Locale' => 'ja'])->assertJson(['client_locale' => 'en-us']);
        $this->get('/save', ['X-App-Locale' => 'ja'])->assertJson(['text' => '保存']);
    }

    /** The page walk, the Inertia seed and a failed validation's entries run in the locale served too, not in Laravel's spelling of it. */
    public function testEverySiteRunsInTheServedLocale(): void
    {
        config()->set('langsys.translate_response.enabled', true);
        $this->fakeClient->seed('es-mx', '__uncategorized__', ['Save' => 'Guardar (MX)']);

        $this->get('/walked', ['X-App-Locale' => 'es'])->assertOk();
        $this->assertSame('es-mx', $this->fakeClient->translatedPages[0]['locale']);

        $this->get('/seed', ['X-App-Locale' => 'es'])->assertJson(['langsys' => ['initialTranslationsLocale' => 'es-mx', 'initialTranslations' => ['__uncategorized__' => ['Save' => 'Guardar (MX)']]]]);

        $this->get('/validated', ['X-App-Locale' => 'es'])->assertJson(['client_locale' => 'es-mx']);

        // A locale the app sets afterwards is its own, and the entries are emitted in it.
        $this->get('/validated-after-switch', ['X-App-Locale' => 'es'])->assertJson(['client_locale' => 'fr']);
    }

    /** The app's missing-key handler is told the locale Laravel holds, as Laravel would tell it, not the one the catalog is served in. */
    public function testTheMissingKeyHandlerIsToldLaravelsLocale(): void
    {
        $missed = [];
        $handled = $this->missingKeyHandler(function (string $key, array $replace, string $locale) use (&$missed) {
            $missed[] = "$key@$locale";

            return null;
        });

        $this->get('/save', ['X-App-Locale' => 'es'])->assertJson(['text' => 'Save']);
        $this->assertSame($handled ? ['Save@es'] : [], $missed);
    }

    /** FRM-4: a notification sent while serving is read in its recipient's locale, not the one the request is served in. */
    public function testANotificationSentWhileServingIsInItsRecipientsLocale(): void
    {
        $this->fakeClient->seed('es-mx', '__uncategorized__', ['Save' => 'Guardar (MX)']);
        $this->fakeClient->seed('fr-fr', '__uncategorized__', ['Save' => 'Enregistrer']);

        $this->get('/notify', ['X-App-Locale' => 'es'])->assertJson(['sent' => ['Enregistrer']]);
    }

    /** FRM-5: a JSON message the app's own lang file answered is in the locale Laravel read it in, not the one the catalog is served in. */
    public function testAnAppsOwnLineIsNamedInLaravelsLocale(): void
    {
        $this->_providersClient();
        $this->seedCatalog('es-mx', ['__uncategorized__' => []]);

        $response = $this->getJson('/api/denied', ['X-App-Locale' => 'es'])->assertStatus(403);

        $this->assertSame('No es tuyo.', $response->json('message'));
        $this->assertSame('es', $response->headers->get('Content-Language'));

        $response = $this->getJson('/api/denied-here', ['X-App-Locale' => 'es'])->assertStatus(403);
        $this->assertSame('es', $response->headers->get('Content-Language'), 'An empty locale is the current one.');
    }

    /**
     * The client the provider builds, whose miss fallback reads the app's lang files, with the
     * project's authorization cached under the core's own key, as a later request finds it.
     */
    private function _providersClient(): void
    {
        config()->set('langsys.api_url', self::UNREACHABLE_API);
        $this->app->forgetInstance(Client::class);
        $this->app->make(Client::class)->getCache()->set('auth_test-project_' . substr(hash('sha256', 'test-key'), 0, 12), self::SERVED);
    }

    /**
     * FRM-1: the locale DetectLocale resolves is set in the spelling the app's own `lang/` uses, as
     * `langsys:sync` reads it, so an app laid out as `lang/es` still answers a miss with its line.
     */
    public function testTheResolvedLocaleIsSetAsTheAppsLangFilesSpellIt(): void
    {
        $this->_providersClient();
        $this->seedCatalog('es-mx', ['__uncategorized__' => []]);

        $this->getJson('/api/denied?locale=es-MX')->assertStatus(403)->assertJsonPath('message', 'No es tuyo.');
        $this->assertSame('es', app()->getLocale());

        $this->seedCatalog('pt-br', ['__uncategorized__' => []]);
        $this->getJson('/api/denied?locale=pt-BR')->assertStatus(403)->assertJsonPath('message', 'Não é seu.')->assertHeader('Content-Language', 'pt-br');
        $this->assertSame('pt_BR', app()->getLocale(), 'A regional spelling is the app\'s too, and the language its line is named in is the wire\'s (WIRE-3).');
    }

    /**
     * FRM-5: a JSON response's entries are in the locale the request is served in, not the one
     * Laravel's spelling of it would map to: `lang/es` holds Laravel's locale as `es`, which alone
     * is the project's default Spanish, while the request was served in `es-es`.
     */
    public function testAJsonFailuresEntriesAreInTheLocaleServed(): void
    {
        $this->_providersClient();
        $this->seedCatalog('es-es', ['Errors' => []]);
        $this->seedCatalog('es-mx', ['Errors' => []]);

        $response = $this->postJson('/api/cards?locale=es-ES', [])->assertStatus(422);

        $this->assertSame('es', app()->getLocale(), "Control: Laravel's locale is the app's own spelling.");
        $this->assertSame('es-es', $response->headers->get('Content-Language'));
    }

    /** GATE-10: a page is marked resolved in the locale it was served in, so one the project does not serve is the base, and stays source. */
    public function testAPageServedInTheBaseForAnUnservedLocaleIsNotMarked(): void
    {
        $this->fakeClient->seed('es-mx', '__uncategorized__', ['Save' => 'Guardar (MX)']);

        $this->assertStringContainsString('data-ls-resolved="es-mx"', $this->get('/page', ['X-App-Locale' => 'es'])->getContent(), 'Control: a translated page is marked.');
        $this->assertStringNotContainsString('data-ls-resolved', $this->get('/page', ['X-App-Locale' => 'ja'])->getContent());
    }

    /** The same mapping when DetectLocale resolves: a bare language in the query string. */
    public function testABareLanguageCandidateMapsToTheProjectsDefault(): void
    {
        $this->get('/probe?locale=es')->assertJson(['app_locale' => 'es', 'client_locale' => 'es-mx']);
    }

    /** A locale the project does not serve is served as the base locale; Laravel's own locale is left as the app set it. */
    public function testALocaleTheAppResolvedThatTheProjectDoesNotServeIsServedAsTheBase(): void
    {
        $this->get('/probe', ['X-App-Locale' => 'ja'])->assertJson(['app_locale' => 'ja', 'client_locale' => 'en-us']);
    }

    public function testWithNothingResolvedTheQueryWinsAndPersistsToTheCookie(): void
    {
        $response = $this->withUnencryptedCookie('langsys_locale', 'fr-FR')
            ->get('/probe?locale=es-ES', ['Accept-Language' => 'de-DE']);

        $response->assertJson(['app_locale' => 'es', 'client_locale' => 'es-es']);
        $this->assertSame('es-ES', $response->getCookie('langsys_locale', false)?->getValue());
        $this->assertSame([], $this->_vary($response), 'The URL is already the cache key.');
    }

    public function testAQueryChoicePersistsToTheSessionWhenConfigured(): void
    {
        config()->set('langsys.locale.persist', 'session');

        $response = $this->get('/probe?locale=es-ES');

        $this->assertNull($response->getCookie('langsys_locale', false));
        $this->assertSame('es-ES', session('langsys_locale'));
    }

    public function testTheCookieBeatsTheHeaderAndTheResponseVariesOnCookie(): void
    {
        $response = $this->withUnencryptedCookie('langsys_locale', 'fr-FR')->get('/probe', ['Accept-Language' => 'de-DE']);

        $response->assertJson(['app_locale' => 'fr-FR']);
        $this->assertSame(['Cookie'], $this->_vary($response));
    }

    public function testASessionValueIsAStoredLocale(): void
    {
        $response = $this->withSession(['langsys_locale' => 'it-IT'])->get('/probe', ['Accept-Language' => 'de-DE']);

        $response->assertJson(['app_locale' => 'it-IT']);
        $this->assertSame(['Cookie'], $this->_vary($response));
    }

    public function testTheHeaderIsNegotiatedAndTheResponseVariesOnIt(): void
    {
        $response = $this->get('/probe', ['Accept-Language' => 'ja,de-DE;q=0.9,en;q=0.8']);

        $response->assertJson(['app_locale' => 'de-DE']);
        $this->assertSame(['Accept-Language'], $this->_vary($response));
    }

    /** The configured order is the app's: here the header is asked before the query string. */
    public function testTheSourcesAreAskedInTheConfiguredOrder(): void
    {
        config()->set('langsys.locale.sources', ['header', 'query']);

        $response = $this->get('/probe?locale=es-ES', ['Accept-Language' => 'de-DE']);

        $response->assertJson(['app_locale' => 'de-DE']);
        $this->assertSame(['Accept-Language'], $this->_vary($response));
    }

    /** A source the app does not list is never read, and so never varied on. */
    public function testASourceNotListedIsNotRead(): void
    {
        config()->set('langsys.locale.sources', ['query']);

        $response = $this->get('/probe', ['Accept-Language' => 'de-DE']);

        $response->assertJson(['app_locale' => 'en-US']);
        $this->assertSame([], $this->_vary($response));
    }

    /** `supported` narrows what the project serves: es-ES is served by the project, but not accepted here. */
    public function testTheSupportedListNarrowsTheProjectsLocales(): void
    {
        config()->set('langsys.locale.supported', ['en-US', 'it-IT']);

        $response = $this->get('/probe?locale=es-ES', ['Accept-Language' => 'it-IT']);

        $response->assertJson(['app_locale' => 'it-IT']);
        $this->assertNull($response->getCookie('langsys_locale', false));
    }

    public function testAnUnsupportedCookieFallsThroughAndIsNotReset(): void
    {
        $response = $this->withUnencryptedCookie('langsys_locale', 'ja-JP')->get('/probe', ['Accept-Language' => 'it-IT']);

        $response->assertJson(['app_locale' => 'it-IT']);
        $this->assertNull($response->getCookie('langsys_locale', false));
    }

    /** A locale the app's `lang/` has no spelling of is set as canonical BCP 47. */
    public function testAQueryValueIsNormalized(): void
    {
        $this->get('/probe?locale=it_it')->assertJson(['app_locale' => 'it-IT', 'client_locale' => 'it-it']);
    }

    /** RFC 7231: q=0 means "not acceptable". Nothing usable, so the base locale; the header was still consulted. */
    public function testWithNothingUsableTheBaseLocaleIsServed(): void
    {
        $response = $this->get('/probe', ['Accept-Language' => 'de;q=0']);

        $response->assertJson(['app_locale' => 'en-US', 'client_locale' => 'en-us']);
        $this->assertSame(['Accept-Language'], $this->_vary($response));
    }

    /** With the project's locales unreadable nothing can be validated, so nothing is chosen and the page still renders. */
    public function testAnUnreachableProjectLeavesTheAppLocaleAlone(): void
    {
        $this->_serve(null);

        $response = $this->get('/probe?locale=es-ES');

        $response->assertOk()->assertJson(['app_locale' => 'en']);
        $this->assertNull($response->getCookie('langsys_locale', false));
    }
}
