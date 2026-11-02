<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\Mapper;
use MapperTest\MapperDbTestCase;

/**
 * Tests for Mapper::serialize() — flat-format export.
 *
 * @covers \Mapper\Stdlib\Mapper::serialize
 */
class SerializeTest extends MapperDbTestCase
{
    protected Mapper $mapper;

    public function setUp(): void
    {
        parent::setUp();
        $this->mapper = $this->getServiceLocator()->get('Mapper\Mapper');
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testSerializeNoMappingReturnsNull(): void
    {
        // Force a non-existent mapping name to bypass any state inherited from
        // previous tests sharing the same application instance.
        $this->mapper->setMappingName('__inexistent_mapping__');
        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $this->assertNull($this->mapper->serialize($item));
    }

    public function testSerializeArraySimple(): void
    {
        $this->mapper->setMapping('export_array_simple', [
            'info' => ['label' => 'A', 'querier' => 'omeka', 'to' => 'json'],
            'maps' => [
                ['from' => ['path' => 'dcterms:title'], 'to' => ['field' => 'dc.title']],
                ['from' => ['path' => 'o:id'], 'to' => ['field' => 'id']],
            ],
        ]);

        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $result = $this->mapper->serialize($item);

        $this->assertIsArray($result);
        $this->assertSame('Hello', $result['dc']['title']);
        // serializeValue normalizes to string; a downstream encoder is free to
        // cast back to int when the target format requires it.
        $this->assertSame((string) $item->id(), $result['id']);
    }

    /**
     * A linked resource is output with its title, not as an empty value.
     */
    public function testSerializeLinkedResource(): void
    {
        $this->mapper->setMapping('export_linked_resource', [
            'info' => ['label' => 'A', 'querier' => 'omeka', 'to' => 'json'],
            'maps' => [
                ['from' => ['path' => 'dcterms:relation'], 'to' => ['field' => 'dc.relation']],
            ],
        ]);

        $linked = $this->createItem(['dcterms:title' => 'Linked item']);
        $item = $this->createItem([
            'dcterms:title' => 'Hello',
            'dcterms:relation' => [['type' => 'resource:item', 'value_resource_id' => $linked->id()]],
        ]);
        $result = $this->mapper->serialize($item);

        $this->assertSame('Linked item', $result['dc']['relation'] ?? null);
    }

    public function testSerializeArrayMultipleValues(): void
    {
        $this->mapper->setMapping('export_array_multi', [
            'info' => ['label' => 'A', 'querier' => 'omeka', 'to' => 'json'],
            'maps' => [
                ['from' => ['path' => 'dcterms:title'], 'to' => ['field' => 'dc.title']],
            ],
        ]);

        $item = $this->createItem(['dcterms:title' => ['One', 'Two', 'Three']]);
        $result = $this->mapper->serialize($item);

        $this->assertSame(['One', 'Two', 'Three'], $result['dc']['title']);
    }

    public function testSerializeXmlSimple(): void
    {
        $this->mapper->setMapping('export_xml_simple', [
            'info' => ['label' => 'X', 'querier' => 'omeka', 'to' => 'oai_dc'],
            'params' => [
                'xmlns.oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
                'xmlns.dc' => 'http://purl.org/dc/elements/1.1/',
            ],
            'maps' => [
                ['from' => ['path' => 'dcterms:title'], 'to' => ['field' => '/oai_dc:dc/dc:title']],
                ['from' => ['path' => 'dcterms:creator'], 'to' => ['field' => '/oai_dc:dc/dc:creator']],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => ['First', 'Second'],
            'dcterms:creator' => 'Alice',
        ]);

        $doc = $this->mapper->serialize($item);
        $this->assertInstanceOf(\DOMDocument::class, $doc);

        $oaiDcNs = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
        $dcNs = 'http://purl.org/dc/elements/1.1/';

        $roots = $doc->getElementsByTagNameNS($oaiDcNs, 'dc');
        $this->assertSame(1, $roots->length);

        $titles = $doc->getElementsByTagNameNS($dcNs, 'title');
        $this->assertSame(2, $titles->length);
        $this->assertSame('First', $titles->item(0)->textContent);
        $this->assertSame('Second', $titles->item(1)->textContent);

        $creators = $doc->getElementsByTagNameNS($dcNs, 'creator');
        $this->assertSame(1, $creators->length);
        $this->assertSame('Alice', $creators->item(0)->textContent);
    }

    public function testSerializeXmlSharesContainer(): void
    {
        $this->mapper->setMapping('export_xml_shared', [
            'info' => ['label' => 'X', 'querier' => 'omeka', 'to' => 'oai_dc'],
            'params' => [
                'xmlns.oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
                'xmlns.dc'     => 'http://purl.org/dc/elements/1.1/',
            ],
            'maps' => [
                ['from' => ['path' => 'dcterms:title'],   'to' => ['field' => '/oai_dc:dc/dc:title']],
                ['from' => ['path' => 'dcterms:creator'], 'to' => ['field' => '/oai_dc:dc/dc:creator']],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => 'T',
            'dcterms:creator' => 'C',
        ]);

        $doc = $this->mapper->serialize($item);
        $roots = $doc->getElementsByTagNameNS('http://www.openarchives.org/OAI/2.0/oai_dc/', 'dc');
        $this->assertSame(1, $roots->length, 'Container /oai_dc:dc must be created once');
    }

    public function testSerializeDefaultMapWithRaw(): void
    {
        $this->mapper->setMapping('export_default_raw', [
            'info' => ['label' => 'X', 'querier' => 'omeka', 'to' => 'oai_dc'],
            'params' => [
                'xmlns.wrap' => 'urn:test:wrap',
                'xmlns.dc' => 'http://purl.org/dc/elements/1.1/',
            ],
            'maps' => [
                ['from' => ['path' => 'dcterms:title'], 'to' => ['field' => '/wrap:root/dc:title']],
                ['to' => ['field' => '/wrap:root/dc:type'], 'mod' => ['raw' => 'StillImage']],
            ],
        ]);

        $item = $this->createItem(['dcterms:title' => 'T']);
        $doc = $this->mapper->serialize($item);

        $dcNs = 'http://purl.org/dc/elements/1.1/';

        $titles = $doc->getElementsByTagNameNS($dcNs, 'title');
        $this->assertSame(1, $titles->length);
        $this->assertSame('T', $titles->item(0)->textContent);

        $types = $doc->getElementsByTagNameNS($dcNs, 'type');
        $this->assertSame(1, $types->length);
        $this->assertSame('StillImage', $types->item(0)->textContent);
    }

    public function testSerializeMissingPropertyOmitted(): void
    {
        $this->mapper->setMapping('export_missing', [
            'info' => ['label' => 'X', 'querier' => 'omeka', 'to' => 'oai_dc'],
            'params' => ['xmlns.dc' => 'http://purl.org/dc/elements/1.1/'],
            'maps' => [
                ['from' => ['path' => 'dcterms:title'], 'to' => ['field' => '/dc:title']],
                ['from' => ['path' => 'dcterms:creator'], 'to' => ['field' => '/dc:creator']],
            ],
        ]);

        $item = $this->createItem(['dcterms:title' => 'Only title']);
        $doc = $this->mapper->serialize($item);

        $dcNs = 'http://purl.org/dc/elements/1.1/';
        $titles = $doc->getElementsByTagNameNS($dcNs, 'title');
        $this->assertSame(1, $titles->length);
        $this->assertSame('Only title', $titles->item(0)->textContent);

        $creators = $doc->getElementsByTagNameNS($dcNs, 'creator');
        $this->assertSame(0, $creators->length);
    }

    public function testSerializeWithOaiDcMappingFile(): void
    {
        $mappingFile = dirname(dirname(dirname(__DIR__))) . '/data/mapping/dublin-core/omeka_to_oai_dc.xml';
        $this->assertFileExists($mappingFile, 'omeka_to_oai_dc.xml fixture must exist');

        $this->mapper->setMapping('omeka_to_oai_dc', file_get_contents($mappingFile));

        $item = $this->createItem([
            'dcterms:title' => ['Le titre', 'Alt title'],
            'dcterms:creator' => 'Berthereau, Daniel',
            'dcterms:date' => '2026-05-10',
            'dcterms:rights' => 'CC-BY-SA 4.0',
        ]);

        $doc = $this->mapper->serialize($item);
        $this->assertInstanceOf(\DOMDocument::class, $doc);

        $oaiDcNs = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
        $dcNs = 'http://purl.org/dc/elements/1.1/';

        $roots = $doc->getElementsByTagNameNS($oaiDcNs, 'dc');
        $this->assertSame(1, $roots->length);

        $titles = $doc->getElementsByTagNameNS($dcNs, 'title');
        $this->assertSame(2, $titles->length);
        $this->assertSame('Le titre', $titles->item(0)->textContent);

        $creators = $doc->getElementsByTagNameNS($dcNs, 'creator');
        $this->assertSame(1, $creators->length);
        $this->assertSame('Berthereau, Daniel', $creators->item(0)->textContent);

        $dates = $doc->getElementsByTagNameNS($dcNs, 'date');
        $this->assertSame(1, $dates->length);

        $rights = $doc->getElementsByTagNameNS($dcNs, 'rights');
        $this->assertSame('CC-BY-SA 4.0', $rights->item(0)->textContent);

        // Properties not set on the item must not appear.
        $publishers = $doc->getElementsByTagNameNS($dcNs, 'publisher');
        $this->assertSame(0, $publishers->length);
    }

    public function testSerializeOaiDcDumbDownRefinements(): void
    {
        $mappingFile = dirname(dirname(dirname(__DIR__))) . '/data/mapping/dublin-core/omeka_to_oai_dc.xml';
        $this->mapper->setMapping('omeka_to_oai_dc', file_get_contents($mappingFile));

        // Refinements with no value of the base term: created/issued → dc:date,
        // provenance/abstract → dc:description, isPartOf → dc:relation.
        $item = $this->createItem([
            'dcterms:created' => '2020-01-01',
            'dcterms:issued' => '2021-02-02',
            'dcterms:provenance' => 'Ancienne collection X',
            'dcterms:abstract' => 'Résumé',
            'dcterms:isPartOf' => 'Série Y',
            'dcterms:license' => 'CC0',
        ]);

        $doc = $this->mapper->serialize($item);
        $dcNs = 'http://purl.org/dc/elements/1.1/';

        $dates = [];
        foreach ($doc->getElementsByTagNameNS($dcNs, 'date') as $n) {
            $dates[] = $n->textContent;
        }
        sort($dates);
        $this->assertSame(['2020-01-01', '2021-02-02'], $dates);

        $descriptions = [];
        foreach ($doc->getElementsByTagNameNS($dcNs, 'description') as $n) {
            $descriptions[] = $n->textContent;
        }
        sort($descriptions);
        $this->assertSame(['Ancienne collection X', 'Résumé'], $descriptions);

        $relations = $doc->getElementsByTagNameNS($dcNs, 'relation');
        $this->assertSame(1, $relations->length);
        $this->assertSame('Série Y', $relations->item(0)->textContent);

        $rights = $doc->getElementsByTagNameNS($dcNs, 'rights');
        $this->assertSame(1, $rights->length);
        $this->assertSame('CC0', $rights->item(0)->textContent);
    }

    public function testSerializeFormatExplicitArray(): void
    {
        // Explicit target_type overrides path-based detection.
        $this->mapper->setMapping('export_explicit_array', [
            'info' => ['label' => 'A', 'querier' => 'omeka', 'to' => 'json'],
            'params' => ['target_type' => 'array'],
            'maps' => [
                // Path starts with / but target_type forces array.
                ['from' => ['path' => 'dcterms:title'], 'to' => ['field' => '/dc/title']],
            ],
        ]);

        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $result = $this->mapper->serialize($item);

        $this->assertIsArray($result);
    }
}
