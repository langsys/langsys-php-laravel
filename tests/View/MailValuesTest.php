<?php

namespace Langsys\Laravel\Tests\View;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Langsys\Laravel\Tests\TestCase;

/**
 * VAR-5 marks the values of a page a reader walks. Mail and notifications are read by their
 * recipient as sent, and a response that is not HTML by whatever reads that format, so their
 * values reach them as Blade prints them: a marker would show in a plain-text body, and at the
 * start of a Markdown line it turns the line into raw HTML.
 */
class MailValuesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('view.paths', [__DIR__ . '/../Fixtures/views', ...(array) $app['config']->get('view.paths')]);
    }

    protected function defineRoutes($router): void
    {
        $feed = fn () => view('feed', ['url' => 'https://example.com/sitemap.xml']);

        $router->middleware('web')->get('/robots.txt', fn () => response($feed())->header('Content-Type', 'text/plain'));
        $router->middleware('api')->get('/api/feed.xml', fn () => response($feed())->header('Content-Type', 'application/xml'));
        $router->middleware('web')->get('/page', fn () => response($feed()));
        $router->middleware('web')->get('/fragment', fn () => response()->json(['html' => $feed()->render()]));
        $router->middleware('web')->get('/download', fn () => response()->streamDownload(fn () => print($feed()->render()), 'feed.xml'));
        $router->middleware('web')->get('/attachment', fn () => response($feed())->header('Content-Disposition', 'attachment; filename="feed.xml"'));
        $router->middleware('web')->get('/job', function () {
            \Langsys\Laravel\Tests\Fixtures\RenderingJob::dispatchSync();

            return response(\Langsys\Laravel\Tests\Fixtures\RenderingJob::$written)->header('Content-Type', 'text/plain');
        });
        $router->middleware('web')->get('/export.xls', fn () => response()->streamDownload(fn () => print(view('export', ['name' => 'Ana', 'email' => 'ana@example.com'])->render()), 'export.xls', ['Content-Type' => 'application/vnd.ms-excel']));
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(config('view.compiled') . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** A Markdown mail rendered on its own, as a preview renders it, is read as sent too. */
    public function testAMarkdownMailRenderedOnItsOwnIsUnmarked(): void
    {
        $html = (string) (new \Illuminate\Notifications\Messages\MailMessage())->markdown('emails.order', ['name' => 'Ana', 'order' => '1042'])->render();

        $this->assertStringContainsString('Hi Ana, your order 1042 has shipped.', $html);
        $this->assertStringNotContainsString('<!--ls:', $html);
    }

    public function testAMailPrintsItsValuesUnmarked(): void
    {
        Mail::send('emails.order', ['name' => 'Ana', 'order' => '1042'], fn ($message) => $message->to('ana@example.com')->subject('Shipped'));

        $body = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();

        $this->assertSame("<p>Hi Ana, your order 1042 has shipped.</p>\n", $body);
    }

    public function testANotificationMailLineRendersAsMarkdown(): void
    {
        $html = (string) (new MailMessage())->line('Thanks for **joining** us.')->render();

        $this->assertStringContainsString('joining</strong> us.</p>', $html, 'The line is Markdown: a paragraph with its bold run.');
        $this->assertStringNotContainsString('<!--ls:', $html);
    }

    /** A view served as something other than HTML — a robots.txt, an XML feed — reaches its reader as Blade printed it. */
    public function testAResponseThatIsNotHtmlCarriesNoMarkers(): void
    {
        $this->assertSame("<url><loc>https://example.com/sitemap.xml</loc></url>\n", $this->get('/robots.txt')->getContent());
        $this->assertSame("<url><loc>https://example.com/sitemap.xml</loc></url>\n", $this->get('/api/feed.xml')->getContent());
        $this->assertStringContainsString('<!--ls:url-->', $this->get('/page')->getContent(), 'Control: an HTML response keeps them.');
    }

    /** JSON carries HTML a page inserts, as Livewire's updates do, so it keeps its markers for the page's readers. */
    public function testJsonKeepsItsMarkers(): void
    {
        $this->assertSame("<url><loc><!--ls:url-->https://example.com/sitemap.xml<!--/ls--></loc></url>\n", $this->get('/fragment')->json('html'));
    }

    /** A streamed export renders while it is sent, after the response is handed over, and carries no markers either. */
    public function testAStreamedResponseThatIsNotHtmlCarriesNoMarkers(): void
    {
        $this->assertSame("<table><tr><td>Ana</td><td>ana@example.com</td></tr></table>\n", $this->get('/export.xls')->streamedContent());
    }

    /** A download is no page, whatever type it declares, or none: Symfony fills in `text/html`. */
    public function testADownloadCarriesNoMarkers(): void
    {
        $this->assertSame("<url><loc>https://example.com/sitemap.xml</loc></url>\n", $this->get('/download')->streamedContent());
        $this->assertSame("<url><loc>https://example.com/sitemap.xml</loc></url>\n", $this->get('/attachment')->getContent());
    }

    /** A queued job writes for whatever it sends, never the page, even run inside the request. */
    public function testAJobRunInsideTheRequestWritesUnmarked(): void
    {
        \Langsys\Laravel\Tests\Fixtures\RenderingJob::$written = null;
        $this->get('/job');

        $this->assertSame("<url><loc>https://example.com/sitemap.xml</loc></url>\n", \Langsys\Laravel\Tests\Fixtures\RenderingJob::$written);
    }

    /** A command or a job prints for a terminal, an SMS or a file, never a page, so what it renders is unmarked. */
    public function testWhatRendersOutsideARequestIsUnmarked(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->assertSame('<p>Hi Ana</p>', Blade::render('<p>Hi {{ $name }}</p>', ['name' => 'Ana']));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /** Mail is recognised however deep its views nest. */
    public function testDeeplyNestedMailIsUnmarked(): void
    {
        Mail::send('emails.deep', ['name' => 'Ana'], fn ($message) => $message->to('ana@example.com')->subject('Deep'));

        $this->assertSame('<p>Hi <b>Ana</b></p>', trim(app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody()));
    }

    /** Control: the same template rendered for a page marks its values. */
    public function testAPageStillMarksItsValues(): void
    {
        $this->assertSame("<p>Hi <!--ls:name-->Ana<!--/ls--></p>", Blade::render('<p>Hi {{ $name }}</p>', ['name' => 'Ana']));
    }
}
