<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\AutomapFields;
use MapperTest\MapperTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * @covers \Mapper\Stdlib\AutomapFields
 */
class AutomapFieldsTest extends AbstractHttpControllerTestCase
{
    use MapperTestTrait;

    /**
     * @var AutomapFields
     */
    protected $fieldAutomap;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $fieldSpecParser = $this->getServiceLocator()->get('Mapper\FieldSpecParser');
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        $this->fieldAutomap = new AutomapFields(
            $api,
            $easyMeta,
            $fieldSpecParser,
            $translator,
            $logger,
            []
        );
    }

    /**
     * @dataProvider patternParsingProvider
     */
    public function testPatternParsing(string $input, array $expected): void
    {
        $result = ($this->fieldAutomap)([$input], [
            'output_full_matches' => true,
            'output_property_id' => true,
        ]);

        // Get first result.
        $actual = $result[0][0] ?? null;
        if ($actual === null) {
            $this->fail("No result for input: $input");
        }

        // Check key fields.
        if (isset($expected['field'])) {
            $this->assertEquals($expected['field'], $actual['field'], "Field mismatch for: $input");
        }
        if (isset($expected['datatype'])) {
            $this->assertEquals($expected['datatype'], $actual['datatype'], "Datatype mismatch for: $input");
        }
        if (isset($expected['language'])) {
            $this->assertEquals($expected['language'], $actual['language'], "Language mismatch for: $input");
        }
        if (isset($expected['is_public'])) {
            $this->assertEquals($expected['is_public'], $actual['is_public'], "Visibility mismatch for: $input");
        }
        if (isset($expected['pattern'])) {
            $this->assertEquals($expected['pattern'], $actual['pattern'], "Pattern mismatch for: $input");
        }
    }

    public function patternParsingProvider(): array
    {
        return [
            'simple term' => [
                'dcterms:title',
                ['field' => 'dcterms:title', 'datatype' => [], 'language' => null],
            ],
            'term with datatype' => [
                'dcterms:creator ^^literal',
                ['field' => 'dcterms:creator', 'datatype' => ['literal']],
            ],
            'term with multiple datatypes' => [
                'dcterms:subject ^^uri ^^literal',
                ['field' => 'dcterms:subject', 'datatype' => ['uri', 'literal']],
            ],
            'term with language' => [
                'dcterms:description @fra',
                ['field' => 'dcterms:description', 'language' => 'fra'],
            ],
            'term with visibility private' => [
                'dcterms:rights §private',
                ['field' => 'dcterms:rights', 'is_public' => 'private'],
            ],
            'term with visibility public' => [
                'dcterms:source §public',
                ['field' => 'dcterms:source', 'is_public' => 'public'],
            ],
            'term with pattern' => [
                'dcterms:date ~ {{ value|date("Y-m-d") }}',
                ['field' => 'dcterms:date', 'pattern' => '{{ value|date("Y-m-d") }}'],
            ],
            'full specification' => [
                'dcterms:title ^^literal @en §private ~ {{ value|upper }}',
                [
                    'field' => 'dcterms:title',
                    'datatype' => ['literal'],
                    'language' => 'en',
                    'is_public' => 'private',
                    'pattern' => '{{ value|upper }}',
                ],
            ],
            'resource datatype' => [
                'dcterms:relation ^^resource:item',
                ['field' => 'dcterms:relation', 'datatype' => ['resource:item']],
            ],
            'dc shorthand' => [
                'dc:title',
                ['field' => 'dcterms:title'],
            ],
        ];
    }

    public function testMultipleTargets(): void
    {
        $result = ($this->fieldAutomap)(['dcterms:title | dcterms:alternative'], [
            'output_full_matches' => true,
        ]);

        $this->assertCount(2, $result[0]);
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
        $this->assertEquals('dcterms:alternative', $result[0][1]['field']);
    }

    public function testSingleTargetPreservesPatternWithPipe(): void
    {
        // The main use of single_target is to preserve patterns with | inside.
        // Test a pattern containing |.
        $result = ($this->fieldAutomap)(['dcterms:title ~ {{ value|upper }}'], [
            'output_full_matches' => true,
            'single_target' => true,
        ]);

        // Pattern with ~ also triggers single-target mode automatically.
        $this->assertCount(1, $result[0]);
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
        $this->assertEquals('{{ value|upper }}', $result[0][0]['pattern']);
    }

    public function testPipeInPatternWithoutSingleTarget(): void
    {
        // Even without single_target, patterns with ~ prevent splitting.
        $result = ($this->fieldAutomap)(['dcterms:title ~ {{ value|upper }}'], [
            'output_full_matches' => true,
            'single_target' => false,
        ]);

        // Should still work because ~ presence prevents splitting.
        $this->assertCount(1, $result[0]);
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
    }

    public function testSimpleOutput(): void
    {
        $result = ($this->fieldAutomap)(['dcterms:title ^^literal @fra'], [
            'output_full_matches' => false,
        ]);

        // Simple output returns just the term.
        $this->assertEquals('dcterms:title', $result[0][0]);
    }

    public function testLocalNameMatching(): void
    {
        $result = ($this->fieldAutomap)(['title'], [
            'output_full_matches' => true,
            'check_names_alone' => true,
        ]);

        // "title" should resolve to "dcterms:title".
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
    }

    public function testLabelMatching(): void
    {
        // Labels with vocabulary name containing space.
        $result = ($this->fieldAutomap)(['Dublin Core:Title'], [
            'output_full_matches' => true,
        ]);

        // "Dublin Core:Title" should resolve to "dcterms:title".
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
    }

    public function testLabelMatchingCaseInsensitive(): void
    {
        // Labels are case-insensitive.
        $result = ($this->fieldAutomap)(['dublin core:title'], [
            'output_full_matches' => true,
        ]);

        // Lower-case label should also resolve.
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
    }

    public function testLocalLabelMatching(): void
    {
        // Property label alone (without vocabulary prefix).
        $result = ($this->fieldAutomap)(['Title'], [
            'output_full_matches' => true,
            'check_names_alone' => true,
        ]);

        // "Title" (local label) should resolve to "dcterms:title".
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
    }

    public function testLocalLabelMatchingCaseInsensitive(): void
    {
        // Property label alone, lowercase.
        $result = ($this->fieldAutomap)(['title'], [
            'output_full_matches' => true,
            'check_names_alone' => true,
        ]);

        // "title" matches both local name and local label.
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
    }

    public function testLabelWithQualifiers(): void
    {
        // Label with spaces AND qualifiers.
        $result = ($this->fieldAutomap)(['Dublin Core:Title ^^literal @fra'], [
            'output_full_matches' => true,
        ]);

        // Should resolve field AND parse qualifiers.
        $this->assertEquals('dcterms:title', $result[0][0]['field']);
        $this->assertEquals(['literal'], $result[0][0]['datatype']);
        $this->assertEquals('fra', $result[0][0]['language']);
    }

    public function testNoCheckField(): void
    {
        $result = ($this->fieldAutomap)(['nonexistent:field ^^literal'], [
            'output_full_matches' => true,
            'check_field' => false,
        ]);

        // Should still parse even if field doesn't exist.
        $this->assertEquals('nonexistent:field', $result[0][0]['field']);
        $this->assertEquals(['literal'], $result[0][0]['datatype']);
    }

    public function testRawValuePattern(): void
    {
        $result = ($this->fieldAutomap)(['dcterms:license ~ "Public Domain"'], [
            'output_full_matches' => true,
        ]);

        $this->assertArrayHasKey('raw', $result[0][0]);
        $this->assertEquals('Public Domain', $result[0][0]['raw']);
        $this->assertNull($result[0][0]['pattern']);
    }

    public function testEmptyInput(): void
    {
        $result = ($this->fieldAutomap)([''], [
            'output_full_matches' => true,
        ]);

        $this->assertNull($result[0]);
    }

    public function testPreservesInputKeys(): void
    {
        $input = [
            'a' => 'dcterms:title',
            'b' => 'dcterms:creator',
            'c' => '',
        ];

        $result = ($this->fieldAutomap)($input, [
            'output_full_matches' => false,
        ]);

        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertEquals('dcterms:title', $result['a'][0]);
        $this->assertEquals('dcterms:creator', $result['b'][0]);
        $this->assertNull($result['c']);
    }

    public function testCustomMap(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $fieldSpecParser = $this->getServiceLocator()->get('Mapper\FieldSpecParser');

        $customMap = [
            'My Title' => 'dcterms:title',
            'Author' => 'dcterms:creator',
        ];

        // No translator, no logger.
        $fieldAutomap = new AutomapFields(
            $api,
            $easyMeta,
            $fieldSpecParser,
            null,
            null,
            $customMap
        );

        $result = $fieldAutomap(['My Title', 'Author'], [
            'output_full_matches' => true,
        ]);

        $this->assertEquals('dcterms:title', $result[0][0]['field']);
        $this->assertEquals('dcterms:creator', $result[1][0]['field']);
    }
}
