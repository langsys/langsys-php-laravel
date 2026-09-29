<?php

namespace Langsys\Laravel\Tests;

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

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', SetsAppLocale::class, 'langsys.locale'])->get('/probe', fn () => response()->json([
            'app_locale'    => app()->getLocale(),
            'client_locale' => $this->app->make(Client::class)->getLocale(),
        ]));
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

    /** The same mapping when DetectLocale resolves: a bare language in the query string. */
    public function testABareLanguageCandidateMapsToTheProjectsDefault(): void
    {
        $this->get('/probe?locale=es')->assertJson(['app_locale' => 'es-MX', 'client_locale' => 'es-mx']);
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

        $response->assertJson(['app_locale' => 'es-ES', 'client_locale' => 'es-es']);
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

    public function testAQueryValueIsNormalized(): void
    {
        $this->get('/probe?locale=pt_br')->assertJson(['app_locale' => 'pt-BR']);
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
