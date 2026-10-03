<?php

namespace Langsys\Laravel\Tests\View;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Langsys\Laravel\Tests\TestCase;

/**
 * VAR-5: Blade marks every value it prints in visible text, and nothing else — not an attribute,
 * not `script`, `style`, `title` or `textarea`, not output marked safe, not a translation call.
 */
class ValueMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Compiled views are cached by content hash; each test compiles afresh.
        foreach (glob(config('view.compiled') . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testAValuePrintedInTextIsMarkedWithItsName(): void
    {
        $user = (object) ['name' => 'Ana', 'firstName' => 'Ana'];

        $this->assertSame(
            '<p>Hello <!--ls:name-->Ana<!--/ls-->, welcome back</p>',
            Blade::render('<p>Hello {{ $user->name }}, welcome back</p>', ['user' => $user])
        );
        $this->assertSame('<p><!--ls:items_count-->3<!--/ls--> items</p>', Blade::render('<p>{{ count($items) }} items</p>', ['items' => [1, 2, 3]]));
    }

    /** VAR-2 within one phrase: two expressions sharing a name are each prefixed; the phrase ends at a block. */
    public function testNamesResolveWithinAPhrase(): void
    {
        $html = Blade::render('<p>{{ $a->name }} and {{ $b->name }}</p><p>{{ $a->name }}</p>', ['a' => (object) ['name' => 'X'], 'b' => (object) ['name' => 'Y']]);

        $this->assertSame('<p><!--ls:a_name-->X<!--/ls--> and <!--ls:b_name-->Y<!--/ls--></p><p><!--ls:name-->X<!--/ls--></p>', $html);
        $this->assertSame(
            '<p>Hi <b><!--ls:a_name-->X<!--/ls--></b> and <!--ls:b_name-->Y<!--/ls--></p>',
            Blade::render('<p>Hi <b>{{ $a->name }}</b> and {{ $b->name }}</p>', ['a' => (object) ['name' => 'X'], 'b' => (object) ['name' => 'Y']]),
            'An inline element does not end the phrase.'
        );
    }

    public function testNothingOutsideVisibleTextIsMarked(): void
    {
        $data = ['name' => 'Ana', 'html' => new HtmlString('<em>Ana</em>')];

        foreach ([
            '<input value="{{ $name }}">'                 => '<input value="Ana">',
            '<a title="{{ $name }}" href="/">x</a>'       => '<a title="Ana" href="/">x</a>',
            '<title>{{ $name }}</title>'                  => '<title>Ana</title>',
            '<script>var n = "{{ $name }}";</script>'     => '<script>var n = "Ana";</script>',
            '<style>.{{ $name }} {}</style>'              => '<style>.Ana {}</style>',
            '<textarea>{{ $name }}</textarea>'            => '<textarea>Ana</textarea>',
            '<p>{!! $name !!}</p>'                        => '<p>Ana</p>',
            '<p>{{ $html }}</p>'                          => '<p><em>Ana</em></p>',
            '<p>{{ __(\'Hello\') }}</p>'                  => '<p>Hello</p>',
            '<!-- a > b: {{ $name }} --><p>x</p>'         => '<!-- a > b: Ana --><p>x</p>',
            '<p>@{{ $name }}</p>'                         => '<p>{{ $name }}</p>',
        ] as $template => $expected) {
            $this->assertSame($expected, Blade::render($template, $data), $template);
        }
    }

    public function testBladesOwnEscapingIsKept(): void
    {
        $this->assertSame('<p><!--ls:name-->&lt;b&gt;&amp;amp;<!--/ls--></p>', Blade::render('<p>{{ $name }}</p>', ['name' => '<b>&amp;']));

        Blade::withoutDoubleEncoding();

        try {
            // Another template, so it compiles under the new echo format rather than from the cache.
            $this->assertSame('<div><!--ls:name-->&lt;b&gt;&amp;<!--/ls--></div>', Blade::render('<div>{{ $name }}</div>', ['name' => '<b>&amp;']));
        } finally {
            (fn () => $this->echoFormat = 'e(%s)')->call(app('blade.compiler'));
        }
    }

    public function testSwitchedOffNothingIsMarked(): void
    {
        config()->set('langsys.enabled', false);

        $this->assertSame('<p>Hello Ana</p>', Blade::render('<p>Hello {{ $name }}</p>', ['name' => 'Ana']));
    }
}
