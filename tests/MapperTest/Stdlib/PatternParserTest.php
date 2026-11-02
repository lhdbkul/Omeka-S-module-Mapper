<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\PatternParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PatternParser class.
 *
 * @covers \Mapper\Stdlib\PatternParser
 */
class PatternParserTest extends TestCase
{
    protected PatternParser $parser;

    public function setUp(): void
    {
        $this->parser = new PatternParser();
    }

    // Basic tests

    public function testEmptyPatternReturnsEmptyResult(): void
    {
        $result = $this->parser->parse('');
        $this->assertSame(PatternParser::EMPTY_RESULT, $result);
    }

    public function testPatternWithNoExpressions(): void
    {
        $result = $this->parser->parse('Just plain text');
        $this->assertSame('Just plain text', $result['pattern']);
        $this->assertEmpty($result['replace']);
        $this->assertEmpty($result['filters']);
        $this->assertTrue($result['is_simple']);
        $this->assertFalse($result['has_filters']);
    }

    // Single brace expressions

    public function testSingleBraceReplacement(): void
    {
        $result = $this->parser->parse('{path}');
        $this->assertContains('{path}', $result['replace']);
        $this->assertTrue($result['is_simple']);
    }

    public function testMultipleSingleBraceReplacements(): void
    {
        $result = $this->parser->parse('{first} and {second}');
        $this->assertCount(2, $result['replace']);
        $this->assertContains('{first}', $result['replace']);
        $this->assertContains('{second}', $result['replace']);
    }

    /**
     * Omeka value paths contain "@" (e.g. "@value"), which must be matched as a
     * single-brace replacement so it is resolved from the data.
     */
    public function testSingleBraceReplacementWithOmekaValuePath(): void
    {
        $result = $this->parser->parse('{dcterms:title.0.@value}');
        $this->assertContains('{dcterms:title.0.@value}', $result['replace']);

        $result = $this->parser->parse('item-{dcterms:title.0.@value}');
        $this->assertContains('{dcterms:title.0.@value}', $result['replace']);
    }

    // Double brace expressions

    public function testDoubleBraceReplacement(): void
    {
        $result = $this->parser->parse('{{ value }}');
        $this->assertContains('{{ value }}', $result['replace']);
        $this->assertTrue($result['is_simple']);
    }

    public function testDoubleBraceWithFilter(): void
    {
        $result = $this->parser->parse('{{ value|upper }}');
        $this->assertContains('{{ value|upper }}', $result['filters']);
        $this->assertEmpty($result['replace']);
        $this->assertFalse($result['is_simple']);
        $this->assertTrue($result['has_filters']);
    }

    public function testDoubleBraceWithMultipleFilters(): void
    {
        $result = $this->parser->parse('{{ value|upper|trim }}');
        $this->assertContains('{{ value|upper|trim }}', $result['filters']);
        $this->assertTrue($result['has_filters']);
    }

    // Mixed expressions

    public function testMixedReplacementsAndTwig(): void
    {
        $result = $this->parser->parse('Prefix {{ value|upper }} and {path}');
        $this->assertContains('{{ value|upper }}', $result['filters']);
        $this->assertContains('{path}', $result['replace']);
    }

    public function testNestedBracesDontConfuse(): void
    {
        $result = $this->parser->parse("{{ value|table({'key': 'val'}) }}");
        $this->assertContains("{{ value|table({'key': 'val'}) }}", $result['filters']);
        // The inner braces should not be extracted as separate replacements.
        $this->assertNotContains("{'key': 'val'}", $result['replace']);
    }

    // Twig has replace detection

    public function testTwigHasReplaceTrue(): void
    {
        $result = $this->parser->parse('{{ {path}|upper }}');
        $this->assertCount(1, $result['filters']);
        // The filters_has_replace should indicate the inner replacement.
        $this->assertNotEmpty($result['filters_has_replace']);
    }

    public function testTwigHasReplaceFalse(): void
    {
        $result = $this->parser->parse('{{ value|upper }}');
        // The filters_has_replace for simple twig should be false.
        foreach ($result['filters_has_replace'] as $hasReplace) {
            $this->assertFalse((bool) $hasReplace);
        }
    }

    // extractPath tests

    public function testExtractPathFromSingleBrace(): void
    {
        $path = $this->parser->extractPath('{my_path}');
        $this->assertSame('my_path', $path);
    }

    public function testExtractPathFromDoubleBrace(): void
    {
        $path = $this->parser->extractPath('{{ my_value }}');
        $this->assertSame('my_value', $path);
    }

    public function testExtractPathFromTwigRemovesFilter(): void
    {
        $path = $this->parser->extractPath('{{ value|upper }}');
        $this->assertSame('value', $path);
    }

    public function testExtractPathFromTwigWithMultipleFilters(): void
    {
        $path = $this->parser->extractPath('{{ value|upper|lower }}');
        $this->assertSame('value', $path);
    }

    public function testExtractPathTrimsWhitespace(): void
    {
        $path = $this->parser->extractPath('{{   spaced   }}');
        $this->assertSame('spaced', $path);
    }

    // extractFilters tests

    public function testExtractFiltersSingleFilter(): void
    {
        $filters = $this->parser->extractFilters('{{ value|upper }}');
        $this->assertSame(['upper'], $filters);
    }

    public function testExtractFiltersMultipleFilters(): void
    {
        $filters = $this->parser->extractFilters('{{ value|upper|lower|trim }}');
        $this->assertSame(['upper', 'lower', 'trim'], $filters);
    }

    public function testExtractFiltersWithArguments(): void
    {
        $filters = $this->parser->extractFilters("{{ value|table('code') }}");
        $this->assertSame(['table'], $filters);
    }

    public function testExtractFiltersNoFilters(): void
    {
        $filters = $this->parser->extractFilters('{{ value }}');
        $this->assertSame([], $filters);
    }

    public function testExtractFiltersFromSingleBrace(): void
    {
        $filters = $this->parser->extractFilters('{path}');
        $this->assertSame([], $filters);
    }

    // hasExpressions tests

    public function testHasExpressionsTrue(): void
    {
        $this->assertTrue($this->parser->hasExpressions('{path}'));
        $this->assertTrue($this->parser->hasExpressions('{{ value }}'));
        $this->assertTrue($this->parser->hasExpressions('text {path} more'));
    }

    public function testHasExpressionsFalse(): void
    {
        $this->assertFalse($this->parser->hasExpressions('plain text'));
        $this->assertFalse($this->parser->hasExpressions(''));
    }

    // isLiteral tests

    public function testIsLiteralTrue(): void
    {
        $this->assertTrue($this->parser->isLiteral('plain text'));
        $this->assertTrue($this->parser->isLiteral(''));
        $this->assertTrue($this->parser->isLiteral('no braces here'));
    }

    public function testIsLiteralFalse(): void
    {
        $this->assertFalse($this->parser->isLiteral('{path}'));
        $this->assertFalse($this->parser->isLiteral('{{ value }}'));
    }

    // isSingleReplacement tests

    public function testIsSingleReplacementTrue(): void
    {
        $this->assertTrue($this->parser->isSingleReplacement('{path}'));
        $this->assertTrue($this->parser->isSingleReplacement('{{ value }}'));
        $this->assertTrue($this->parser->isSingleReplacement('  {{ spaced }}  '));
    }

    public function testIsSingleReplacementFalse(): void
    {
        $this->assertFalse($this->parser->isSingleReplacement('prefix {path}'));
        $this->assertFalse($this->parser->isSingleReplacement('{path} suffix'));
        $this->assertFalse($this->parser->isSingleReplacement('{a} {b}'));
        $this->assertFalse($this->parser->isSingleReplacement('plain text'));
        // Twig filters are not simple replacements.
        $this->assertFalse($this->parser->isSingleReplacement('{{ value|upper }}'));
    }

    // buildPattern tests

    public function testBuildPatternSimple(): void
    {
        $result = $this->parser->buildPattern(null, '{{ value }}', null);
        $this->assertSame('{{ value }}', $result);
    }

    public function testBuildPatternWithPrepend(): void
    {
        $result = $this->parser->buildPattern('prefix-', '{{ value }}', null);
        $this->assertSame('prefix-{{ value }}', $result);
    }

    public function testBuildPatternWithAppend(): void
    {
        $result = $this->parser->buildPattern(null, '{{ value }}', '-suffix');
        $this->assertSame('{{ value }}-suffix', $result);
    }

    public function testBuildPatternComplete(): void
    {
        $result = $this->parser->buildPattern('http://', '{{ value }}', '/path');
        $this->assertSame('http://{{ value }}/path', $result);
    }

    public function testBuildPatternEmptyStrings(): void
    {
        $result = $this->parser->buildPattern('', '{{ value }}', '');
        $this->assertSame('{{ value }}', $result);
    }

    // Edge cases

    public function testPatternWithEscapedBraces(): void
    {
        // Pattern with JSON-like content inside twig.
        $result = $this->parser->parse("{{ value|table({'a': 1, 'b': 2}) }}");
        $this->assertCount(1, $result['filters']);
    }

    public function testMultilinePattern(): void
    {
        $pattern = "First: {{ value|upper }}\nSecond: {path}";
        $result = $this->parser->parse($pattern);
        $this->assertContains('{{ value|upper }}', $result['filters']);
        $this->assertContains('{path}', $result['replace']);
    }

    public function testComplexRealWorldPattern(): void
    {
        $pattern = 'http://example.org/{{ identifier|trim }}/page/{page_num}';
        $result = $this->parser->parse($pattern);

        $this->assertContains('{{ identifier|trim }}', $result['filters']);
        $this->assertContains('{page_num}', $result['replace']);
        $this->assertFalse($result['is_simple']);
    }

    public function testPatternWithUnicodeContent(): void
    {
        $result = $this->parser->parse('Titre: {{ title }} - État: {état}');
        $this->assertContains('{{ title }}', $result['replace']);
        $this->assertContains('{état}', $result['replace']);
    }

    public function testSplitPipes(): void
    {
        $this->assertSame(['value', 'upper'], PatternParser::splitPipes(' value | upper '));
        $this->assertSame(
            ['value', "replace({'|': ' '})", 'join("|")', "split('\\'|')"],
            PatternParser::splitPipes("value|replace({'|': ' '})|join(\"|\")|split('\\'|')")
        );
        $this->assertSame(['value', 'slice(0, 2)'], PatternParser::splitPipes('value||slice(0, 2)'));
    }
}
