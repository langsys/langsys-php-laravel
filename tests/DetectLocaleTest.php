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

    /**
     * Accept-Language parsing is the SDK's (LocaleDetector::fromAcceptLanguage);
     * these two cases pin the consequences at the Laravel boundary, where the
     * middleware canonicalizes the result onto app()->getLocale().
     *
     * "en" carries an implicit q=1 and outranks "es-MX;q=0.9". The wrapper's
     * own parser used to take the first locale-shaped substring and pick
     * es-MX — a visitor who preferred English was served Spanish.
     */
    public function testHighestQualityLanguageWinsRegardlessOfOrder(): void
    {
        $response = $this->get('/probe', ['Accept-Language' => 'en,es-MX;q=0.9']);

        // A bare language gains a region: the API addresses translations by
        // xx-yy codes, so "en" alone could never match a project locale.
        $response->assertJson(['app_locale' => 'en-EN', 'client_locale' => 'en-en']);
    }

    /** RFC 7231: q=0 means "not acceptable", so it must not be selected. */
    public function testZeroQualityLanguageIsRejectedAndFallsThrough(): void
    {
        config()->set('langsys.locale.sources', ['header']);

        $response = $this->get('/probe', ['Accept-Language' => 'de;q=0']);

        $response->assertJson(['app_locale' => 'en']);
    }

    public function testNoSourceLeavesAppLocaleAlone(): void
    {
        config()->set('langsys.locale.sources', ['query']);

        $response = $this->get('/probe');

        $response->assertJson(['app_locale' => 'en']);
    }
}
