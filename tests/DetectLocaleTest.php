<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Route;

class DetectLocaleTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'langsys.locale'])->get('/probe', fn () => response()->json([
            'app_locale'    => app()->getLocale(),
            'client_locale' => $this->app->make(\Langsys\SDK\Client::class)->getLocale(),
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableCookieEncryption();
    }

    public function testQueryParamWinsAndPersistsToCookie(): void
    {
        $response = $this->get('/probe?locale=es-ES');

        // App gets canonical BCP 47; the SDK normalizes to its lowercase form.
        $response->assertJson(['app_locale' => 'es-ES', 'client_locale' => 'es-es']);
        $this->assertSame('es-ES', $response->getCookie('langsys_locale', false)?->getValue());
    }

    public function testCookieSourceIsUsedWhenNoQueryParam(): void
    {
        $response = $this->withUnencryptedCookie('langsys_locale', 'fr-FR')->get('/probe');

        $response->assertJson(['app_locale' => 'fr-FR']);
    }

    public function testAcceptLanguageHeaderIsTheFallback(): void
    {
        $response = $this->get('/probe', ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8']);

        $response->assertJson(['app_locale' => 'de-DE']);
    }

    public function testLocaleIsNormalized(): void
    {
        $response = $this->get('/probe?locale=pt_br');

        $response->assertJson(['app_locale' => 'pt-BR']);
    }

    public function testUnsupportedLocaleFallsThroughToNextSource(): void
    {
        config()->set('langsys.locale.supported', ['en-US', 'it-IT']);

        $response = $this->get('/probe?locale=es-ES', ['Accept-Language' => 'it-IT']);

        $response->assertJson(['app_locale' => 'it-IT']);
    }

    public function testNoSourceLeavesAppLocaleAlone(): void
    {
        config()->set('langsys.locale.sources', ['query']);

        $response = $this->get('/probe');

        $response->assertJson(['app_locale' => 'en']);
    }
}
