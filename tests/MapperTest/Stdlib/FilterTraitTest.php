<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\FilterTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test class that uses FilterTrait for testing purposes.
 */
class FilterTraitTestClass
{
    use FilterTrait;

    /**
     * Config of the mapping, for named tables.
     *
     * @var object|null
     */
    public $mapperConfig;

    /**
     * Expose processFilter for testing.
     */
    public function testProcessFilter($value, string $filter)
    {
        return $this->processFilter($value, $filter);
    }

    /**
     * Expose applyFilters for testing.
     */
    public function testApplyFilters(
        string $pattern,
        array $filterVars,
        array $filters,
        array $filterHasReplace = [],
        array $replace = []
    ): string {
        return $this->applyFilters($pattern, $filterVars, $filters, $filterHasReplace, $replace);
    }

    /**
     * Expose extractList for testing.
     */
    public function testExtractList(string $args, array $keys = []): array
    {
        return $this->extractList($args, $keys);
    }

    /**
     * Expose extractAssociative for testing.
     */
    public function testExtractAssociative(string $args): array
    {
        return $this->extractAssociative($args);
    }

    /**
     * Expose filterDateIso for testing.
     */
    public function testFilterDateIso(string $value): string
    {
        return $this->filterDateIso($value);
    }

    /**
     * Expose filterDateRevert for testing.
     */
    public function testFilterDateRevert(string $value): string
    {
        return $this->filterDateRevert($value);
    }

    /**
     * Expose filterDateSql for testing.
     */
    public function testFilterDateSql(string $value): string
    {
        return $this->filterDateSql($value);
    }

    /**
     * Expose filterUnimarcCoordinates for testing.
     */
    public function testFilterUnimarcCoordinates(string $value): string
    {
        return $this->filterUnimarcCoordinates($value);
    }

    /**
     * Expose filterUnimarcTimeHexa for testing.
     */
    public function testFilterUnimarcTimeHexa(string $value): string
    {
        return $this->filterUnimarcTimeHexa($value);
    }

    /**
     * Expose noidCheckBnf for testing.
     */
    public function testNoidCheckBnf(string $value): string
    {
        return $this->noidCheckBnf($value);
    }
}

/**
 * Tests for FilterTrait.
 *
 * @covers \Mapper\Stdlib\FilterTrait
 */
class FilterTraitTest extends TestCase
{
    protected FilterTraitTestClass $filter;

    public function setUp(): void
    {
        $this->filter = new FilterTraitTestClass();
    }

    // String filters

    public function testLowerFilter(): void
    {
        $this->assertSame('hello world', $this->filter->testProcessFilter('Hello WORLD', 'lower'));
    }

    public function testUpperFilter(): void
    {
        $this->assertSame('HELLO WORLD', $this->filter->testProcessFilter('Hello World', 'upper'));
    }

    public function testCapitalizeFilter(): void
    {
        $this->assertSame('Hello world', $this->filter->testProcessFilter('hello world', 'capitalize'));
    }

    public function testTitleFilter(): void
    {
        $this->assertSame('Hello World', $this->filter->testProcessFilter('hello world', 'title'));
    }

    public function testMultibyteStringFilters(): void
    {
        $this->assertSame('Élan vital', $this->filter->testProcessFilter('élan vital', 'capitalize'));
        $this->assertSame('Élan Vital Été', $this->filter->testProcessFilter('élan vital été', 'title'));
        $this->assertSame('Hello WORLD', $this->filter->testProcessFilter('hello WORLD', 'title'));
        $this->assertSame(['éa', 'ïb'], $this->filter->testProcessFilter('éaïb', 'split("", 2)'));
    }

    /**
     * A "|" inside the arguments of a filter is not a separator of filters.
     */
    public function testPipeInsideFilterArguments(): void
    {
        $expression = "{{ value|replace({'|': ' / '})|upper }}";
        $result = $this->filter->testApplyFilters($expression, ['value' => 'a|b'], [$expression]);
        $this->assertSame('A / B', $result);
    }

    public function testTrimFilter(): void
    {
        $this->assertSame('hello', $this->filter->testProcessFilter('  hello  ', 'trim'));
    }

    public function testTrimWithMask(): void
    {
        $this->assertSame('hello', $this->filter->testProcessFilter('xxxhelloxxx', 'trim("x")'));
    }

    public function testTrimLeft(): void
    {
        $this->assertSame('hello  ', $this->filter->testProcessFilter('  hello  ', 'trim(" ", "left")'));
    }

    public function testTrimRight(): void
    {
        $this->assertSame('  hello', $this->filter->testProcessFilter('  hello  ', 'trim(" ", "right")'));
    }

    public function testSliceString(): void
    {
        $this->assertSame('ell', $this->filter->testProcessFilter('hello', 'slice(1, 3)'));
    }

    public function testSliceFromStart(): void
    {
        $this->assertSame('he', $this->filter->testProcessFilter('hello', 'slice(0, 2)'));
    }

    public function testSliceNegativeStart(): void
    {
        $this->assertSame('lo', $this->filter->testProcessFilter('hello', 'slice(-2, 2)'));
    }

    public function testFirstString(): void
    {
        $this->assertSame('h', $this->filter->testProcessFilter('hello', 'first'));
    }

    public function testLastString(): void
    {
        $this->assertSame('o', $this->filter->testProcessFilter('hello', 'last'));
    }

    public function testLengthString(): void
    {
        $this->assertSame('5', $this->filter->testProcessFilter('hello', 'length'));
    }

    public function testReplaceFilter(): void
    {
        $this->assertSame('hXllo', $this->filter->testProcessFilter('hello', 'replace({"e", "X"})'));
    }

    public function testStriptagsFilter(): void
    {
        $this->assertSame('hello world', $this->filter->testProcessFilter('<p>hello <b>world</b></p>', 'striptags'));
    }

    public function testSplitWithDelimiter(): void
    {
        $result = $this->filter->testProcessFilter('a,b,c', 'split(",", 10)');
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testSplitWithoutDelimiter(): void
    {
        $result = $this->filter->testProcessFilter('hello', 'split("", 1)');
        $this->assertSame(['h', 'e', 'l', 'l', 'o'], $result);
    }

    // Numeric filters
    public function testAbsPositiveNumeric(): void
    {
        $this->assertSame('42', $this->filter->testProcessFilter('42', 'abs'));
        $this->assertSame('3.14', $this->filter->testProcessFilter('3.14', 'abs'));
    }

    public function testAbsNegativeNumeric(): void
    {
        $this->assertSame('42', $this->filter->testProcessFilter('-42', 'abs'));
        $this->assertSame('3.14', $this->filter->testProcessFilter('-3.14', 'abs'));
    }

    public function testAbsNonNumeric(): void
    {
        $this->assertSame('hello', $this->filter->testProcessFilter('hello', 'abs'));
    }

    // URL encoding

    public function testUrlEncode(): void
    {
        $this->assertSame('hello%20world', $this->filter->testProcessFilter('hello world', 'url_encode'));
    }

    public function testUrlEncodeSpecialChars(): void
    {
        $this->assertSame('foo%2Fbar%3Fbaz%3D1', $this->filter->testProcessFilter('foo/bar?baz=1', 'url_encode'));
    }

    // HTML escaping

    public function testEscapeFilter(): void
    {
        $this->assertSame('&lt;p&gt;test&lt;/p&gt;', $this->filter->testProcessFilter('<p>test</p>', 'escape'));
    }

    public function testEFilterAlias(): void
    {
        $this->assertSame('&lt;p&gt;test&lt;/p&gt;', $this->filter->testProcessFilter('<p>test</p>', 'e'));
    }

    // Join/implode filters

    public function testJoinFilter(): void
    {
        $result = $this->filter->testProcessFilter('', 'join(", ", "a", "b", "c")');
        $this->assertSame('a, b, c', $result);
    }

    public function testImplodeFilter(): void
    {
        $result = $this->filter->testProcessFilter('', 'implode("-", "x", "y", "z")');
        $this->assertSame('x-y-z', $result);
    }

    public function testImplodevFilter(): void
    {
        $result = $this->filter->testProcessFilter('', 'implodev(", ", "a", "", "b", "", "c")');
        $this->assertSame('a, b, c', $result);
    }

    // Format filter

    public function testFormatFilter(): void
    {
        $result = $this->filter->testProcessFilter('Hello %s, you have %d messages', 'format("World", 5)');
        $this->assertSame('Hello World, you have 5 messages', $result);
    }

    // Domain-specific filters

    public function testFilterDateIsoBasic(): void
    {
        // "d1605110512" should become "1605-11-05T12"
        $result = $this->filter->testFilterDateIso('d1605110512');
        $this->assertSame('1605-11-05T12', $result);
    }

    public function testFilterDateIsoFull(): void
    {
        $result = $this->filter->testFilterDateIso('d19850901141236');
        $this->assertSame('1985-09-01T14:12:36', $result);
    }

    public function testFilterDateIsoWithUndetermined(): void
    {
        // Values containing 'u' (undetermined) should be returned as-is.
        $result = $this->filter->testFilterDateIso('1985u9u1');
        $this->assertSame('1985u9u1', $result);
    }

    public function testFilterDateIsoNegative(): void
    {
        $result = $this->filter->testFilterDateIso('-05000101');
        $this->assertSame('-0500-01-01', $result);
    }

    public function testFilterDateRevertSlashes(): void
    {
        // "15/06/2023" should become "2023-06-15"
        $result = $this->filter->testFilterDateRevert('15/06/2023');
        $this->assertSame('2023-06-15', $result);
    }

    public function testFilterDateRevertDashes(): void
    {
        $result = $this->filter->testFilterDateRevert('15-06-2023');
        $this->assertSame('2023-06-15', $result);
    }

    public function testFilterDateRevertShortYear(): void
    {
        $result = $this->filter->testFilterDateRevert('15/06/23');
        $this->assertSame('2023-06-15', $result);
    }

    public function testFilterDateSql(): void
    {
        // "19850901141236.0" should become "1985-09-01 14:12:36"
        $result = $this->filter->testFilterDateSql('19850901141236.0');
        $this->assertSame('1985-09-01 14:12:36', $result);
    }

    public function testFilterUnimarcCoordinatesWest(): void
    {
        // "w0241207" should become "W 24°12'7""
        $result = $this->filter->testFilterUnimarcCoordinates('w0241207');
        $this->assertSame('W 24°12\'7"', $result);
    }

    public function testFilterUnimarcCoordinatesNorth(): void
    {
        $result = $this->filter->testFilterUnimarcCoordinates('+0481234');
        $this->assertSame('N 48°12\'34"', $result);
    }

    public function testFilterUnimarcCoordinatesSouth(): void
    {
        $result = $this->filter->testFilterUnimarcCoordinates('-0331500');
        $this->assertSame('S 33°15\'0"', $result);
    }

    public function testFilterUnimarcTimeHexaFull(): void
    {
        $result = $this->filter->testFilterUnimarcTimeHexa('143015');
        $this->assertSame('14h30m15s', $result);
    }

    public function testFilterUnimarcTimeHexaHoursOnly(): void
    {
        $result = $this->filter->testFilterUnimarcTimeHexa('120000');
        $this->assertSame('12h', $result);
    }

    public function testFilterUnimarcTimeHexaWithZeroMinutes(): void
    {
        // "150027" has 0 minutes, so it should show "15h0m27s"
        $result = $this->filter->testFilterUnimarcTimeHexa('150027');
        $this->assertSame('15h0m27s', $result);
    }

    // BnF NOID check

    public function testNoidCheckBnf(): void
    {
        // Simple check that function returns valid character.
        $result = $this->filter->testNoidCheckBnf('cb123456789');
        $this->assertIsString($result);
        $this->assertSame(1, strlen($result));
    }

    // Extract list

    public function testExtractListSimple(): void
    {
        $result = $this->filter->testExtractList('"a", "b", "c"');
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testExtractListWithNumbers(): void
    {
        $result = $this->filter->testExtractList('"text", 42, 3.14');
        $this->assertSame(['text', '42', '3.14'], $result);
    }

    public function testExtractListWithKeys(): void
    {
        $result = $this->filter->testExtractList('"val1", "val2"', ['key1', 'key2', 'key3']);
        $this->assertSame(['key1' => 'val1', 'key2' => 'val2', 'key3' => ''], $result);
    }

    // Extract associative

    public function testExtractAssociativeSimple(): void
    {
        $result = $this->filter->testExtractAssociative('"key1", "value1", "key2", "value2"');
        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    // ApplyFilters

    public function testApplyFiltersBasic(): void
    {
        $pattern = '{{ value|upper }}';
        $filterVars = ['value' => 'hello'];
        $filters = ['{{ value|upper }}'];

        $result = $this->filter->testApplyFilters($pattern, $filterVars, $filters);
        $this->assertSame('HELLO', $result);
    }

    public function testApplyFiltersChained(): void
    {
        $pattern = '{{ value|trim|upper }}';
        $filterVars = ['value' => '  hello  '];
        $filters = ['{{ value|trim|upper }}'];

        $result = $this->filter->testApplyFilters($pattern, $filterVars, $filters);
        $this->assertSame('HELLO', $result);
    }

    public function testApplyFiltersMultiple(): void
    {
        $pattern = 'Name: {{ name|upper }}, City: {{ city|lower }}';
        $filterVars = ['name' => 'john', 'city' => 'PARIS'];
        $filters = ['{{ name|upper }}', '{{ city|lower }}'];

        $result = $this->filter->testApplyFilters($pattern, $filterVars, $filters);
        $this->assertSame('Name: JOHN, City: paris', $result);
    }

    public function testApplyFiltersWithReplacements(): void
    {
        $pattern = 'Result: {{ value|upper }}';
        $filterVars = ['value' => 'test'];
        $filters = ['{{ value|upper }}'];
        $replace = ['{{ prefix }}' => 'PRE_'];

        $result = $this->filter->testApplyFilters($pattern, $filterVars, $filters, [], $replace);
        $this->assertSame('Result: TEST', $result);
    }

    // Edge cases

    public function testProcessFilterWithEmptyValue(): void
    {
        $this->assertSame('', $this->filter->testProcessFilter('', 'upper'));
    }

    public function testProcessFilterUnknownFilter(): void
    {
        // Unknown filters return the value unchanged.
        $this->assertSame('hello', $this->filter->testProcessFilter('hello', 'unknown_filter'));
    }

    // Unicode support

    public function testUpperUnicode(): void
    {
        $this->assertSame('CAFÉ', $this->filter->testProcessFilter('café', 'upper'));
    }

    public function testLowerUnicode(): void
    {
        $this->assertSame('münchen', $this->filter->testProcessFilter('MÜNCHEN', 'lower'));
    }

    public function testLengthUnicode(): void
    {
        $this->assertSame('4', $this->filter->testProcessFilter('café', 'length'));
    }

    public function testSliceUnicode(): void
    {
        $this->assertSame('fé', $this->filter->testProcessFilter('café', 'slice(2, 2)'));
    }

    // table_reverse filter (export direction)

    public function testTableReverseInline(): void
    {
        $result = $this->filter->testProcessFilter('Female', 'table_reverse({"f", "Female", "m", "Male"})');
        $this->assertSame('f', $result);
    }

    public function testTableReverseInlineUnknownReturnsValue(): void
    {
        $result = $this->filter->testProcessFilter('Other', 'table_reverse({"f", "Female", "m", "Male"})');
        $this->assertSame('Other', $result);
    }

    public function testTableReverseInlineEmptyArgs(): void
    {
        $result = $this->filter->testProcessFilter('any', 'table_reverse({})');
        $this->assertSame('any', $result);
    }

    public function testTableInlineThenTableReverseRoundTrip(): void
    {
        $code = 'f';
        $forward = $this->filter->testProcessFilter($code, 'table({"f", "Female", "m", "Male"})');
        $this->assertSame('Female', $forward);

        $back = $this->filter->testProcessFilter($forward, 'table_reverse({"f", "Female", "m", "Male"})');
        $this->assertSame($code, $back);
    }

    /**
     * A named table of another mapping with the same name is not reused.
     */
    public function testTableReverseNamedTableOfAnotherMapping(): void
    {
        $config = fn (array $tables) => new class($tables) {
            private array $tables;

            public function __construct(array $tables)
            {
                $this->tables = $tables;
            }

            public function getSection(string $section): array
            {
                return $section === 'tables' ? $this->tables : [];
            }
        };

        $this->filter->mapperConfig = $config(['gender' => ['f' => 'Female']]);
        $this->assertSame('f', $this->filter->testProcessFilter('Female', "table_reverse('gender')"));

        $this->filter->mapperConfig = $config(['gender' => ['woman' => 'Female']]);
        $this->assertSame('woman', $this->filter->testProcessFilter('Female', "table_reverse('gender')"));
    }

    // filterMetadata

    public function testFilterMetadataExposesAllFilters(): void
    {
        $meta = FilterTraitTestClass::filterMetadata();
        $this->assertIsArray($meta);
        $this->assertNotEmpty($meta);

        // A few sanity checks on critical entries.
        $this->assertArrayHasKey('table', $meta);
        $this->assertArrayHasKey('table_reverse', $meta);
        $this->assertArrayHasKey('upper', $meta);
        $this->assertArrayHasKey('lower', $meta);

        foreach ($meta as $name => $info) {
            $this->assertArrayHasKey('reversible', $info, "Filter $name missing reversible");
            $this->assertArrayHasKey('inverse', $info, "Filter $name missing inverse");
            $this->assertArrayHasKey('category', $info, "Filter $name missing category");
            $this->assertIsBool($info['reversible']);
        }
    }

    public function testFilterMetadataLossyFiltersFlaggedNonReversible(): void
    {
        $meta = FilterTraitTestClass::filterMetadata();
        foreach (['upper', 'lower', 'trim', 'striptags', 'first', 'last', 'length', 'slice', 'abs'] as $lossy) {
            $this->assertFalse($meta[$lossy]['reversible'], "$lossy must be flagged non-reversible");
        }
    }

    public function testFilterMetadataInverses(): void
    {
        $meta = FilterTraitTestClass::filterMetadata();
        $this->assertSame('lower', $meta['upper']['inverse']);
        $this->assertSame('upper', $meta['lower']['inverse']);
        $this->assertSame('table_reverse', $meta['table']['inverse']);
        $this->assertSame('table', $meta['table_reverse']['inverse']);
        $this->assertSame('dateRevert', $meta['dateRevert']['inverse']);
    }
}
