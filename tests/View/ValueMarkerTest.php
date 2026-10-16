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
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('view.paths', [__DIR__ . '/../Fixtures/views', ...(array) $app['config']->get('view.paths')]);
    }

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
        $this->assertSame(
            '<p><!--ls:value-->3<!--/ls--> and <!--ls:value_2-->8<!--/ls--> or <!--ls:value_3-->1<!--/ls--> and <!--ls:value_4-->2<!--/ls--></p>',
            Blade::render('<p>{{ $a + $b }} and {{ $c * $d }} or {{ abs($a - $b) }} and {{ abs($c - $d) }}</p>', ['a' => 1, 'b' => 2, 'c' => 4, 'd' => 2]),
            'Unnameable values are each their own placeholder, however alike their shapes.'
        );
        $this->assertSame(
            '<p><!--ls:name-->Ana<!--/ls--> and <!--ls:name-->Ana<!--/ls--></p>',
            Blade::render('<p>{{ $name }} and {{ $name }}</p>', ['name' => 'Ana']),
            'An expression that names itself is one placeholder wherever it repeats.'
        );
        $this->assertSame(
            '<p><!--ls:value-->3<!--/ls--> and <!--ls:value_2-->3<!--/ls--></p>',
            Blade::render('<p>{{ $a + $b }} and {{ $a + $b }}</p>', ['a' => 1, 'b' => 2]),
            'An unnameable expression is its own placeholder every time, even when its source repeats.'
        );
        $this->assertSame(
            '<p><!--ls:items_count-->3<!--/ls--> of <!--ls:items_count-->3<!--/ls--></p>',
            Blade::render('<p>{{ count($items) }} of {{ $items->count() }}</p>', ['items' => collect([1, 2, 3])]),
            'Two spellings of one shape are one expression.'
        );
        $this->assertSame(
            '<p><!--ls:first_name-->Ana<!--/ls--> or <!--ls:first_name_2-->Bo<!--/ls--></p>',
            Blade::render('<p>{{ $firstName }} or {{ $first_name }}</p>', ['firstName' => 'Ana', 'first_name' => 'Bo']),
            'Two shapes that derive one name are two placeholders: the name plays no part in sameness.'
        );
    }

    public function testNothingOutsideVisibleTextIsMarked(): void
    {
        $data = ['name' => 'Ana', 'html' => new HtmlString('<em>Ana</em>'), 'user' => (object) ['locked' => false, 'attrs' => 'data-x="1"']];

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
            '<p><!-- a > b: {{ $name }} --></p>'          => '<p><!-- a > b: Ana --></p>',
            // An element closed, or one that holds nothing, leaves the value outside any element.
            '<p>Hi</p>{{ $name }}'                        => '<p>Hi</p>Ana',
            '<meta charset="utf-8">{{ $name }}'           => '<meta charset="utf-8">Ana',
            '<p>@{{ $name }}</p>'                         => '<p>{{ $name }}</p>',
            // A `>` inside a directive's arguments or a raw echo in a tag does not end the tag.
            '<input @disabled($user->locked) value="{{ $name }}">'                  => '<input  value="Ana">',
            '<span @class([\'p-4\', \'bold\' => true]) title="{{ $name }}">x</span>' => '<span class="p-4 bold" title="Ana">x</span>',
            '<div {!! $user->attrs !!} title="{{ $name }}">x</div>'                => '<div data-x="1" title="Ana">x</div>',
        ] as $template => $expected) {
            $this->assertSame($expected, Blade::render($template, $data), $template);
        }
    }

    /** An echo Blade accepts compiles: Laravel drops a trailing semicolon, and so does the marker. */
    public function testAnEchoEndingInASemicolonIsMarked(): void
    {
        $this->assertSame('<p><!--ls:name-->Ana<!--/ls--></p>', Blade::render('<p>{{ $name; }}</p>', ['name' => 'Ana']));
    }

    /**
     * A value written in a section, a slot or a component's body lands where another template
     * prints it — a `<title>`, an attribute, a script, a `<textarea>` — so it is printed as Blade
     * prints it; one inside an element its own template opens is marked.
     */
    public function testAValueIsMarkedOnlyWhereItsOwnTemplateSaysItIsText(): void
    {
        $this->assertSame(
            "<html><head><title>My Post</title><meta name=\"description\" content=\"A summary\"><script>var count = 3;</script></head><body><p><!--ls:post-->My Post<!--/ls--></p></body></html>\n",
            view('post', ['post' => 'My Post', 'summary' => 'A summary', 'count' => 3])->render()
        );
        $this->assertSame(
            "<title>Ana page</title><textarea name=\"bio\">I like tea</textarea>",
            trim(Blade::render('<x-profile><x-slot:title>{{ $title }}</x-slot:title>{{ $bio }}</x-profile>', ['title' => 'Ana page', 'bio' => 'I like tea']))
        );
    }

    /**
     * Blade compiles a component tag to `@component` before any precompiler reads the template, so
     * a component's body and its slots start at the top whatever element the component sits in,
     * as a legacy `@component` and `@slot` do; the element around the component is text again after it.
     */
    public function testAComponentInsideAnElementPrintsItsBodyAsBladeDoes(): void
    {
        $data = ['title' => 'Ana page', 'bio' => 'I like tea', 'after' => 'Done'];
        $expected = '<div><title>Ana page</title><textarea name="bio">I like tea</textarea><!--ls:after-->Done<!--/ls--></div>';

        foreach ([
            '<div><x-profile><x-slot:title>{{ $title }}</x-slot:title>{{ $bio }}</x-profile>{{ $after }}</div>',
            '<div><x-profile><x-slot name="title">{{ $title }}</x-slot>{{ $bio }}</x-profile>{{ $after }}</div>',
            "<div>@component('components.profile')@slot('title'){{ \$title }}@endslot{{ \$bio }}@endcomponent{{ \$after }}</div>",
            "<div>@component('components.profile')@slot('title', 'Ana page'){{ \$bio }}@endcomponent{{ \$after }}</div>",
        ] as $template) {
            $this->assertSame($expected, preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], Blade::render($template, $data)), $template);
        }
    }

    /** Blade's legacy escaped echo compiles as Blade compiles it, unmarked. */
    public function testALegacyEscapedEchoCompiles(): void
    {
        $this->assertSame('<p>Ana</p>', Blade::render('<p>{{{ $name }}}</p>', ['name' => 'Ana']));
    }

    /** A tag whose name is printed is a tag, not text. */
    public function testAPrintedTagNameIsATag(): void
    {
        $this->assertSame(
            '<div><h2 class="title">Hello <!--ls:name-->Ana<!--/ls--></h2></div>',
            Blade::render('<div><{{ $tag }} class="title">Hello {{ $name }}</{{ $tag }}></div>', ['tag' => 'h2', 'name' => 'Ana'])
        );
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
