<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\FieldSpecParser;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * @covers \Mapper\Stdlib\FieldSpecParser
 */
class FieldSpecParserTest extends AbstractHttpControllerTestCase
{
    protected FieldSpecParser $parser;

    public function setUp(): void
    {
        parent::setUp();
        $this->parser = $this->getApplication()->getServiceManager()->get('Mapper\FieldSpecParser');
    }

    public function testEmptySpec(): void
    {
        $this->assertSame([
            'field' => null,
            'property_id' => null,
            'datatype' => null,
            'language' => null,
            'is_public' => null,
        ], $this->parser->parse('  '));
    }

    public function testField(): void
    {
        $result = $this->parser->parse('dcterms:title');
        $this->assertSame('dcterms:title', $result['field']);
        $this->assertIsInt($result['property_id']);
    }

    public function testUnknownFieldHasNoPropertyId(): void
    {
        $result = $this->parser->parse('o:resource_class');
        $this->assertSame('o:resource_class', $result['field']);
        $this->assertNull($result['property_id']);
    }

    public function testDatatypes(): void
    {
        $this->assertSame(['literal'], $this->parser->parse('dcterms:title ^^literal')['datatype']);
        $this->assertSame(['literal', 'uri'], $this->parser->parse('dcterms:title ^^literal ^^uri')['datatype']);
    }

    public function testLanguageAndVisibility(): void
    {
        $this->assertSame('fra', $this->parser->parse('dcterms:title @fra')['language']);
        $this->assertTrue($this->parser->parse('dcterms:title §public')['is_public']);
        $this->assertFalse($this->parser->parse('dcterms:title §private')['is_public']);
    }

    public function testCompleteSpecWithPattern(): void
    {
        $result = $this->parser->parse('dcterms:title ^^literal @fra §public ~ {{ value|upper }}');
        $this->assertSame('dcterms:title', $result['field']);
        $this->assertSame(['literal'], $result['datatype']);
        $this->assertSame('fra', $result['language']);
        $this->assertTrue($result['is_public']);
    }

    /**
     * A custom vocab label with spaces is kept as a single datatype.
     */
    public function testQuotedCustomVocabLabel(): void
    {
        $result = $this->parser->parse('dcterms:type ^^customvocab:"My Type List" @fra');
        $this->assertSame('dcterms:type', $result['field']);
        $this->assertSame('fra', $result['language']);
        $this->assertCount(1, $result['datatype']);
        $this->assertStringStartsWith('customvocab:', $result['datatype'][0]);
    }
}
