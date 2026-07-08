<?php

namespace Langsys\Laravel\Tests;

use Illuminate\Support\Facades\Blade;

class BladeDirectiveTest extends TestCase
{
    public function testDirectiveCompilesToEscapedHelperCall(): void
    {
        $compiled = Blade::compileString("@t('Welcome {name}', 'Home', ['name' => \$name])");

        $this->assertSame("<?php echo e(t('Welcome {name}', 'Home', ['name' => \$name])); ?>", $compiled);
    }

    public function testHelperTranslatesAndInterpolates(): void
    {
        $this->app->setLocale('es-ES');
        $this->fakeClient->seed('es-ES', 'Home', ['Welcome {name}' => 'Bienvenida {name}']);

        $this->assertSame('Bienvenida Sarah', t('Welcome {name}', 'Home', ['name' => 'Sarah']));
    }

    public function testHelperFallsBackToThePhraseAndQueuesIt(): void
    {
        $this->app->setLocale('es-ES');

        $this->assertSame('Brand new phrase', t('Brand new phrase', 'Home'));
        $this->assertSame([['phrase' => 'Brand new phrase', 'category' => 'Home']], $this->fakeClient->queuedPhrases);
    }

    public function testHelperDefaultsToTheAppLocale(): void
    {
        $this->app->setLocale('fr-FR');
        $this->fakeClient->seed('fr-FR', '__uncategorized__', ['Save' => 'Enregistrer']);

        $this->assertSame('Enregistrer', t('Save'));
    }

    public function testRenderedOutputIsEscaped(): void
    {
        $this->app->setLocale('en-US');
        $this->fakeClient->seed('en-US', 'UI', ['Save' => '<script>alert(1)</script>']);

        $rendered = Blade::render("@t('Save', 'UI')");

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }
}
