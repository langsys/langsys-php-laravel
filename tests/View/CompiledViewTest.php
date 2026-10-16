<?php

namespace Langsys\Laravel\Tests\View;

use Illuminate\Support\Facades\Blade;
use Illuminate\Translation\Translator;
use Langsys\Laravel\Tests\TestCase;

/**
 * Laravel keeps a compiled view until its template changes, whatever the configuration, so a view
 * compiled with the package on is served after it is switched off, and one compiled with it off
 * after it is switched on. Each renders as the package stands when it renders.
 */
class CompiledViewTest extends TestCase
{
    private const TEMPLATE = "<p>@lang('Pay now') {{ \$name }}</p>";

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(config('view.compiled') . '/*.php') ?: [] as $file) {
            @unlink($file);
        }

        $this->fakeClient->seed('es-ES', '__uncategorized__', ['Pay now' => '<script>alert(1)</script>Pagar']);
        $this->app->setLocale('es-ES');
    }

    private function _switch(bool $on): void
    {
        config()->set('langsys.enabled', $on);
        $this->app->forgetInstance('translator');
    }

    public function testAViewCompiledOnRendersAsLaravelOnceSwitchedOff(): void
    {
        $this->assertStringContainsString('<!--ls:name-->', Blade::render(self::TEMPLATE, ['name' => 'Ana']), 'Control: compiled with the package on.');

        $this->_switch(false);

        $this->assertSame(Translator::class, get_class(app('translator')), 'Control: Laravel\'s own translator answers.');
        $this->assertSame('<p>Pay now Ana</p>', Blade::render(self::TEMPLATE, ['name' => 'Ana']));
    }

    public function testAChoiceCompiledOnRendersAsLaravelOnceSwitchedOff(): void
    {
        $template = "<p>@choice(':count apple|:count apples', 2)</p>";
        Blade::render($template);

        $this->_switch(false);

        $this->assertSame('<p>2 apples</p>', Blade::render($template));
    }

    public function testAViewCompiledOffEscapesCatalogTextOnceSwitchedOn(): void
    {
        $this->_switch(false);
        $this->assertSame('<p>Pay now Ana</p>', Blade::render(self::TEMPLATE, ['name' => 'Ana']), 'Control: compiled with the package off.');

        $this->_switch(true);

        $this->assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;Pagar <!--ls:name-->Ana<!--/ls--></p>', Blade::render(self::TEMPLATE, ['name' => 'Ana']));
    }
}
