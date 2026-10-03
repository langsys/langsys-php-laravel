<?php

namespace Langsys\Laravel\Tests\Translation;

use Illuminate\Support\Facades\Validator;
use Langsys\Laravel\Tests\TestCase;
use Langsys\Laravel\Translation\CatalogTranslator;
use Langsys\SDK\Client;
use Langsys\SDK\Log\LoggerInterface;
use RuntimeException;

/**
 * Migrate mode answers `__()`, `trans()`, `@lang` and `trans_choice()` from the Langsys catalog
 * (MIG-8). The application keeps only its source-language files: a key resolves to its source
 * line there, and that line — converted to Langsys syntax — is the phrase, never the key (MIG-3).
 * Evidence is the real SDK Client the provider builds, off the network, with catalogs seeded into
 * the cache it reads.
 */
class CatalogTranslatorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('langsys.api_url', self::UNREACHABLE_API);
        $app['config']->set('app.fallback_locale', 'en');
        $app->useLangPath(self::FIXTURES . '/lang');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The Client the provider builds, so the migration files it is given are under test.
        $this->app->forgetInstance(Client::class);
        $this->app['translator']->addNamespace('courier', self::FIXTURES . '/courier-lang');

        // Catalogs that load and hold nothing yet: the SDK registers only after a lookup it could
        // trust, never after a failed fetch. A test seeds its own over these.
        $this->seedCatalog('en', ['messages' => []]);
        $this->seedCatalog('es-es', ['messages' => []]);
    }

    /** @return list<array{phrase: string, category: string}> */
    private function _queued(): array
    {
        return array_values($this->app->make(Client::class)->getPendingPhrases());
    }

    public function testAGroupKeyRendersTheCatalogTranslationOfItsSourceLine(): void
    {
        $this->seedCatalog('es-es', ['messages' => ['Welcome back, {name}' => 'Hola de nuevo, {name}']]);
        $this->app->setLocale('es-ES');

        $this->assertSame('Hola de nuevo, Ana', __('messages.welcome', ['name' => 'Ana']));
        $this->assertSame('Hola de nuevo, Ana', trans('messages.welcome', ['name' => 'Ana']));
    }

    /** MIG-3: the phrase is the source line under its group, never the key. */
    public function testTheKeyIsNeverThePhrase(): void
    {
        $this->seedCatalog('es-es', ['messages' => ['messages.welcome' => 'Wrong: the key was looked up']]);
        $this->app->setLocale('es-ES');

        $this->assertSame('Welcome back, Ana', __('messages.welcome', ['name' => 'Ana']));
    }

    public function testANestedKeyResolvesByPath(): void
    {
        $this->assertSame('Home page', __('messages.nav.home'));
    }

    public function testAJsonKeyResolvesToItsLine(): void
    {
        $this->assertSame('Save changes', __('Save'));

        $this->seedCatalog('es-es', ['__uncategorized__' => ['Save changes' => 'Guardar cambios']]);
        $this->assertSame('Guardar cambios', __('Save', [], 'es-ES'), 'The line is the phrase looked up.');
    }

    /**
     * A `__()` argument is written in Laravel's syntax whether it is a key or the sentence itself,
     * so a sentence no file holds is converted the same way a file's line is: `:name` is a Langsys
     * `{name}`, and the phrase every SDK shares carries no Laravel-only placeholder.
     */
    public function testASentenceNoFileHoldsIsConverted(): void
    {
        $this->assertSame('Hello Ana', __('Hello :name', ['name' => 'Ana']));

        $this->seedCatalog('es-es', ['__uncategorized__' => ['Hello {name}' => 'Hola {name}']]);
        $this->assertSame('Hola Ana', __('Hello :name', ['name' => 'Ana'], 'es-ES'), 'The phrase looked up is `Hello {name}`.');
    }

    /**
     * MIG-2's `__()` row: Laravel substitutes only the placeholders it is passed and prints any
     * other `:word`, and any `|`, as written — so those are registered as written.
     */
    public function testASentenceKeepsWhatLaravelWouldPrintAsWritten(): void
    {
        $this->assertSame('Note:done', __('Note:done'));
        $this->assertSame('Hello :name', __('Hello :name'));
        $this->assertSame('a | b', __('a | b'));

        $this->seedCatalog('es-es', ['__uncategorized__' => ['Note:done' => 'Nota:hecho', 'Hello :name' => 'Hola :name', 'a | b' => 'a o b']]);
        $this->assertSame(['Nota:hecho', 'Hola :name', 'a o b'], [__('Note:done', [], 'es-ES'), __('Hello :name', [], 'es-ES'), __('a | b', [], 'es-ES')], 'Each is looked up as written.');
    }

    /** `:Name` upper-cases a value in Laravel, which `{name}` cannot say: registered verbatim, with a warning. */
    public function testACapitalisingPlaceholderIsLookedUpAsWrittenAndWarned(): void
    {
        $client = $this->app->make(Client::class);
        $logger = new class implements LoggerInterface {
            public array $warnings = [];
            public function debug($message, array $context = []) {}
            public function info($message, array $context = []) {}
            public function warning($message, array $context = []) { $this->warnings[] = $message; }
            public function error($message, array $context = []) {}
            public function log($level, $message, array $context = []) {}
        };
        (new \ReflectionProperty(Client::class, 'logger'))->setValue($client, $logger);

        $this->seedCatalog('es-es', ['__uncategorized__' => ['Hello :Name' => 'Hola :Name']]);

        $this->assertSame('Hola :Name', __('Hello :Name', ['name' => 'ana'], 'es-ES'), 'Looked up as written.');
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString(':Name', $logger->warnings[0]);
    }

    public function testTheFrameworksOwnLinesAnswerWhatTheAppDoesNotDefine(): void
    {
        $this->assertSame('These credentials do not match our records.', __('auth.failed'));
        $this->assertSame('Slow down.', __('auth.throttle'), "The app's own line overrides the framework's.");

        $this->seedCatalog('es-es', ['auth' => ['These credentials do not match our records.' => 'Credenciales incorrectas.', 'Slow down.' => 'Más despacio.']]);
        $this->assertSame('Credenciales incorrectas.', __('auth.failed', [], 'es-ES'), 'Looked up under the group.');
        $this->assertSame('Más despacio.', __('auth.throttle', [], 'es-ES'));
    }

    public function testAPackageKeyResolvesThroughItsNamespaceWithTheAppsOverride(): void
    {
        $this->assertSame('Your parcel is on its way.', __('courier::notices.sent'));
        $this->assertSame('Parcel lost.', __('courier::notices.lost'));
    }

    public function testAPackageKeyNoFileHoldsReturnsTheKeyAndRegistersNothing(): void
    {
        $this->assertSame('courier::notices.missing', __('courier::notices.missing'));
        $this->assertSame([], $this->_queued());
    }

    /** Validation lines belong to the server-messages lane; Laravel's translator keeps answering them. */
    public function testValidationKeysStayWithLaravel(): void
    {
        $this->assertSame('The name is needed.', __('validation.required', ['attribute' => 'name']));

        $validator = Validator::make([], ['name' => 'required']);
        $this->assertSame('The name is needed.', $validator->errors()->first('name'));

        $this->assertSame('The name is needed.', trans_choice('validation.required', 1, ['attribute' => 'name']));

        // A catalog entry spelled like the key is never consulted: the key is Laravel's.
        $this->seedCatalog('es-es', ['__uncategorized__' => ['validation.required' => 'Wrong: Langsys answered']]);
        $this->assertSame('The name is needed.', trans_choice('validation.required', 1, ['attribute' => 'name'], 'es-ES'));
    }

    public function testAPipePluralRendersThroughIcuOverCount(): void
    {
        $this->assertSame('1 apple', trans_choice('messages.apples', 1));
        $this->assertSame('3 apples', trans_choice('messages.apples', 3));
        $this->assertSame('2 apples', trans_choice('messages.apples', ['a', 'b']), 'A countable is counted, as Laravel does.');

        $this->seedCatalog('es-es', ['messages' => ['{count, plural, one {# apple} other {# apples}}' => '{count, plural, one {# manzana} other {# manzanas}}']]);
        $this->assertSame('3 manzanas', trans_choice('messages.apples', 3, [], 'es-ES'), 'The ICU plural is the phrase looked up.');
    }

    /** Laravel's JSON lines are Laravel's syntax too, so their plurals convert like a group's. */
    public function testAJsonPluralRendersThroughIcuOverCount(): void
    {
        $this->assertSame('2 products', trans_choice('Basket', 2));

        $this->seedCatalog('es-es', ['__uncategorized__' => ['{count, plural, one {# product} other {# products}}' => '{count, plural, one {# producto} other {# productos}}']]);
        $this->assertSame('2 productos', trans_choice('Basket', 2, [], 'es-ES'));
    }

    public function testAPipePluralNoFileHoldsIsConvertedToo(): void
    {
        $this->assertSame('4 items', trans_choice(':count item|:count items', 4));

        $this->seedCatalog('es-es', ['__uncategorized__' => ['{count, plural, one {# item} other {# items}}' => '{count, plural, one {# artículo} other {# artículos}}']]);
        $this->assertSame('4 artículos', trans_choice(':count item|:count items', 4, [], 'es-ES'));
    }

    public function testATranslatedPluralSelectsForTheRequestLocale(): void
    {
        $this->seedCatalog('es-es', ['messages' => [
            '{count, plural, one {# apple} other {# apples}}' => '{count, plural, one {# manzana} other {# manzanas}}',
        ]]);
        $this->app->setLocale('es-ES');

        $this->assertSame('1 manzana', trans_choice('messages.apples', 1));
        $this->assertSame('5 manzanas', trans_choice('messages.apples', 5));
    }

    /** Both SDKs key a catalog by lowercase `xx-yy` (WIRE-3); Laravel's own spelling is irrelevant. */
    public function testAnExplicitLocaleIsNormalizedBeforeTheLookup(): void
    {
        $this->seedCatalog('es-es', ['messages' => ['Welcome back, {name}' => 'Hola de nuevo, {name}']]);

        $this->assertSame('Hola de nuevo, Ana', __('messages.welcome', ['name' => 'Ana'], 'es_ES'));
    }

    public function testHasAnswersWhetherAFileHoldsTheKey(): void
    {
        $this->assertTrue($this->app['translator']->has('messages.welcome'));
        $this->assertTrue($this->app['translator']->has('courier::notices.lost'));
        $this->assertFalse($this->app['translator']->has('messages.nope'));
        $this->assertTrue($this->app['translator']->has('validation.required'));
        $this->assertFalse($this->app['translator']->has('Hello :name'), 'A sentence is not a key, however it converts.');
        $this->seedCatalog('es-es', ['__uncategorized__' => ['Save changes' => 'Guardar cambios']]);
        $this->assertFalse($this->app['translator']->has('Save changes', 'es-ES'), 'Nor is a sentence the catalog translates.');
        $this->assertSame([], $this->_queued(), 'Asking is not rendering: nothing registers.');
    }

    /**
     * The files, tiers and order are Laravel's: the app's JSON before its groups, the framework's
     * bundled English only as fallback, and a package's files behind the app's override of them.
     */
    public function testTheSdkIsGivenTheFilesLaravelWouldReadInTheSourceLocale(): void
    {
        $lang = self::FIXTURES . '/lang';
        $framework = dirname((new \ReflectionClass(\Illuminate\Translation\Translator::class))->getFileName()) . '/lang/en';

        $this->assertSame([
            'files'          => [['path' => "$lang/en.json", 'format' => 'laravel'], "$lang/en/auth.php", "$lang/en/messages.php"],
            'fallback_files' => ["$framework/auth.php", "$framework/pagination.php", "$framework/passwords.php"],
            'namespaces'     => ['courier' => [
                'files'          => ["$lang/vendor/courier/en/notices.php"],
                'fallback_files' => [self::FIXTURES . '/courier-lang/en/notices.php'],
            ]],
        ], $this->app->make(Client::class)->getConfig()->getMigration());
    }

    public function testValidationLinesAreNotMigrationSourceFiles(): void
    {
        $this->assertNull($this->app->make(Client::class)->resolveLegacyKey('validation.required'));
    }

    /**
     * The translator is resolved on nearly every request; the Client is not. Building one without
     * credentials throws, so the translator must not build it until a line is actually asked for.
     */
    public function testResolvingTheTranslatorDoesNotBuildTheClient(): void
    {
        // Unset first: Laravel 10 rebuilds an abstract that was already resolved the moment it is rebound.
        unset($this->app[Client::class]);
        $this->app->bind(Client::class, fn () => throw new RuntimeException('The Client was built.'));
        $this->app->forgetInstance('translator');

        $translator = $this->app->make('translator');
        $this->assertInstanceOf(CatalogTranslator::class, $translator);

        $this->expectExceptionMessage('The Client was built.');
        $translator->get('messages.welcome');
    }

    /**
     * FRM-4: mail and notifications are read in the recipient's language. Laravel sends a
     * notification inside the notifiable's `preferredLocale()`, so `__()` answers in it, whatever the
     * request that sent it — and from a queued job, which carries the locale with it.
     */
    public function testANotificationIsTranslatedInTheRecipientsPreferredLocale(): void
    {
        $this->seedCatalog('es-es', ['messages' => ['Welcome back, {name}' => 'Hola de nuevo, {name}']]);
        $this->app->setLocale('en');
        \Langsys\Laravel\Tests\Fixtures\Notifications\RecordingChannel::$sent = [];

        $recipient = new \Langsys\Laravel\Tests\Fixtures\Notifications\Recipient('Ana', 'es-ES');
        $recipient->notify(new \Langsys\Laravel\Tests\Fixtures\Notifications\WelcomeNotification());
        $recipient->notifyNow(new \Langsys\Laravel\Tests\Fixtures\Notifications\WelcomeNotification());

        $this->assertSame(['Hola de nuevo, Ana', 'Hola de nuevo, Ana'], \Langsys\Laravel\Tests\Fixtures\Notifications\RecordingChannel::$sent);
        $this->assertSame('en', $this->app->getLocale(), "The request's own locale is restored.");
    }
}
