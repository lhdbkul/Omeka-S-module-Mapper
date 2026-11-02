<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\TwigXmlEngine;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TwigXmlEngine mini-engine.
 *
 * @covers \Mapper\Stdlib\TwigXmlEngine
 */
class TwigXmlEngineTest extends TestCase
{
    protected TwigXmlEngine $engine;

    public function setUp(): void
    {
        $this->engine = new TwigXmlEngine();
    }

    // Variable interpolation

    public function testRendersPlainText(): void
    {
        $this->assertSame('hello world', $this->engine->render('hello world', []));
    }

    public function testInterpolatesScalarVariable(): void
    {
        $out = $this->engine->render('Hello {{ name }}!', ['name' => 'World']);
        $this->assertSame('Hello World!', $out);
    }

    public function testAutoEscapesXml(): void
    {
        $out = $this->engine->render('<v>{{ x }}</v>', ['x' => 'Café & Thé < 2 €']);
        // & and < must be escaped; quotes pass through with ENT_COMPAT.
        $this->assertSame('<v>Café &amp; Thé &lt; 2 €</v>', $out);
    }

    public function testRawFilterBypassesEscape(): void
    {
        $out = $this->engine->render('{{ x|raw }}', ['x' => '<b>bold</b>']);
        $this->assertSame('<b>bold</b>', $out);
    }

    public function testFilterChainEscapesAfterFilters(): void
    {
        $out = $this->engine->render('{{ x|upper }}', ['x' => 'hello & bye']);
        $this->assertSame('HELLO &amp; BYE', $out);
    }

    public function testDotNotationResolution(): void
    {
        $out = $this->engine->render('{{ a.b.c }}', ['a' => ['b' => ['c' => 'leaf']]]);
        $this->assertSame('leaf', $out);
    }

    public function testStringLiteral(): void
    {
        $out = $this->engine->render("{{ 'literal' }}", []);
        $this->assertSame('literal', $out);
    }

    public function testMissingVariableYieldsEmpty(): void
    {
        $out = $this->engine->render('Hello {{ missing }}!', []);
        $this->assertSame('Hello !', $out);
    }

    // Conditionals

    public function testIfTruthy(): void
    {
        $out = $this->engine->render('{% if x %}yes{% endif %}', ['x' => 'truthy']);
        $this->assertSame('yes', $out);
    }

    public function testIfFalsyOnMissing(): void
    {
        $out = $this->engine->render('{% if x %}yes{% endif %}', []);
        $this->assertSame('', $out);
    }

    public function testIfFalsyOnEmptyArray(): void
    {
        $out = $this->engine->render('{% if x %}yes{% endif %}', ['x' => []]);
        $this->assertSame('', $out);
    }

    public function testIfFalsyOnEmptyString(): void
    {
        $out = $this->engine->render('{% if x %}yes{% endif %}', ['x' => '']);
        $this->assertSame('', $out);
    }

    public function testIfElse(): void
    {
        $tpl = '{% if x %}A{% else %}B{% endif %}';
        $this->assertSame('A', $this->engine->render($tpl, ['x' => 1]));
        $this->assertSame('B', $this->engine->render($tpl, []));
    }

    // Loops

    public function testForOverArray(): void
    {
        $out = $this->engine->render('{% for v in items %}<x>{{ v }}</x>{% endfor %}', [
            'items' => ['a', 'b', 'c'],
        ]);
        $this->assertSame('<x>a</x><x>b</x><x>c</x>', $out);
    }

    public function testForOverEmptyArrayProducesNothing(): void
    {
        $out = $this->engine->render('{% for v in items %}x{% endfor %}', ['items' => []]);
        $this->assertSame('', $out);
    }

    public function testForOverNonArrayProducesNothing(): void
    {
        $out = $this->engine->render('{% for v in items %}x{% endfor %}', ['items' => 'scalar']);
        $this->assertSame('', $out);
    }

    public function testLoopIndex(): void
    {
        $out = $this->engine->render(
            '{% for v in items %}{{ loop.index }}:{{ v }};{% endfor %}',
            ['items' => ['a', 'b', 'c']]
        );
        $this->assertSame('1:a;2:b;3:c;', $out);
    }

    // Nested constructs

    public function testNestedIfInsideFor(): void
    {
        $tpl = '{% for v in items %}{% if v %}<x>{{ v }}</x>{% endif %}{% endfor %}';
        $out = $this->engine->render($tpl, ['items' => ['a', '', 'c']]);
        $this->assertSame('<x>a</x><x>c</x>', $out);
    }

    public function testNestedForInsideIf(): void
    {
        $tpl = '{% if items %}<list>{% for v in items %}<x>{{ v }}</x>{% endfor %}</list>{% endif %}';
        $this->assertSame(
            '<list><x>a</x><x>b</x></list>',
            $this->engine->render($tpl, ['items' => ['a', 'b']])
        );
        $this->assertSame('', $this->engine->render($tpl, ['items' => []]));
    }

    public function testCommentsAreStripped(): void
    {
        $tpl = "before{# this is a comment #}after";
        $this->assertSame('beforeafter', $this->engine->render($tpl, []));
    }

    public function testMultilineCommentIsStripped(): void
    {
        $tpl = "a{#\nmulti\nline\n#}b";
        $this->assertSame('ab', $this->engine->render($tpl, []));
    }

    public function testNestedForInsideFor(): void
    {
        $tpl = '{% for row in rows %}<r>{% for c in row %}{{ c }}{% endfor %}</r>{% endfor %}';
        $out = $this->engine->render($tpl, [
            'rows' => [['a', 'b'], ['c']],
        ]);
        $this->assertSame('<r>ab</r><r>c</r>', $out);
    }

    public function testEscapeFilterDoesNotEscapeTwice(): void
    {
        $this->assertSame('<v>a &amp; b</v>', $this->engine->render('<v>{{ x|e }}</v>', ['x' => 'a & b']));
        $this->assertSame('<v>a &amp; b</v>', $this->engine->render('<v>{{ x|escape }}</v>', ['x' => 'a & b']));
    }

    public function testPipeAndBracesInsideFilterArguments(): void
    {
        $out = $this->engine->render('<v>{{ x|replace({"|": " / "}) }}</v>', ['x' => 'a|b']);
        $this->assertSame('<v>a / b</v>', $out);
    }

    public function testLoopIndexWithStringKeys(): void
    {
        $out = $this->engine->render(
            '{% for v in list %}{{ loop.index }}:{{ loop.key }}{% if loop.last %}.{% endif %} {% endfor %}',
            ['list' => ['a' => 1, 'b' => 2]]
        );
        $this->assertSame('1:a 2:b. ', $out);
    }
}
