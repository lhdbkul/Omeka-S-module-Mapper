<?php declare(strict_types=1);

namespace MapperTest\Api\Adapter;

use MapperTest\Controller\AbstractControllerTestCase;
use MapperTest\MapperTestTrait;

/**
 * Tests for the Mapper API Adapter.
 *
 * @covers \Mapper\Api\Adapter\MapperAdapter
 */
class MapperAdapterTest extends AbstractControllerTestCase
{
    use MapperTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    // =========================================================================
    // CRUD Operations
    // =========================================================================

    public function testCreateMapper(): void
    {
        $mapper = $this->createMapper('Test Create', $this->getSampleIniMapping());

        $this->assertNotNull($mapper);
        $this->assertSame('Test Create', $mapper->label());
        $this->assertNotEmpty($mapper->mapping());
    }

    public function testReadMapper(): void
    {
        $created = $this->createMapper('Test Read', $this->getSampleIniMapping());

        $mapper = $this->api()->read('mappers', $created->id())->getContent();

        $this->assertSame($created->id(), $mapper->id());
        $this->assertSame('Test Read', $mapper->label());
    }

    public function testUpdateMapper(): void
    {
        $mapper = $this->createMapper('Original', $this->getSampleIniMapping());

        $this->api()->update('mappers', $mapper->id(), [
            'o:label' => 'Updated Label',
        ]);

        $updated = $this->api()->read('mappers', $mapper->id())->getContent();
        $this->assertSame('Updated Label', $updated->label());
    }

    /**
     * Only the label and the mapping are hydrated from the data, so other keys
     * do not cause errors.
     */
    public function testCreateMapperIgnoresOtherKeys(): void
    {
        $services = $this->getServiceLocator();
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $mapper = $this->api()->create('mappers', [
            'o:id' => 999999,
            'o:label' => '  Other keys  ',
            'o-mapper:mapping' => $this->getSampleIniMapping(),
            'o:owner' => ['o:id' => $user->getId()],
            'o:created' => '2000-01-01T00:00:00+00:00',
            'o:modified' => 'invalid',
        ])->getContent();
        $this->createdMapperIds[] = $mapper->id();

        $this->assertNotSame(999999, $mapper->id());
        $this->assertSame('Other keys', $mapper->label());
        $this->assertSame($this->getSampleIniMapping(), $mapper->mapping());
        $this->assertSame($user->getId(), $mapper->owner()->id());
        $this->assertNotSame('2000', $mapper->created()->format('Y'));
    }

    public function testUpdateMapperKeepsMappingAndAcceptsPrefixes(): void
    {
        $mapper = $this->createMapper('Prefixes', $this->getSampleIniMapping());

        $this->api()->update('mappers', $mapper->id(), ['o:label' => 'Prefixes 2']);
        $this->assertSame($this->getSampleIniMapping(), $this->api()->read('mappers', $mapper->id())->getContent()->mapping());

        foreach (['o-module-mapper:mapping', 'o-mapper:mapping', 'o:mapping'] as $key) {
            $this->api()->update('mappers', $mapper->id(), [$key => "[maps]\n~ = dcterms:title ~ $key"], [], ['isPartial' => true]);
            $this->assertSame("[maps]\n~ = dcterms:title ~ $key", $this->api()->read('mappers', $mapper->id())->getContent()->mapping());
        }
    }

    public function testDeleteMapper(): void
    {
        $mapper = $this->createMapper('To Delete', $this->getSampleIniMapping());
        $mapperId = $mapper->id();

        // Remove from tracking.
        $this->createdMapperIds = array_diff($this->createdMapperIds, [$mapperId]);

        $this->api()->delete('mappers', $mapperId);

        $this->expectException(\Omeka\Api\Exception\NotFoundException::class);
        $this->api()->read('mappers', $mapperId);
    }

    // =========================================================================
    // Search/Browse
    // =========================================================================

    public function testSearchMappers(): void
    {
        $this->createMapper('Search Test 1', $this->getSampleIniMapping());
        $this->createMapper('Search Test 2', $this->getSampleXmlMapping());

        $response = $this->api()->search('mappers');
        $mappers = $response->getContent();

        $this->assertIsArray($mappers);
        $this->assertGreaterThanOrEqual(2, count($mappers));
    }

    public function testSearchMappersWithLabel(): void
    {
        $this->createMapper('Unique Label ABC', $this->getSampleIniMapping());

        $response = $this->api()->search('mappers', ['label' => 'Unique Label ABC']);
        $mappers = $response->getContent();

        $this->assertCount(1, $mappers);
        $this->assertSame('Unique Label ABC', $mappers[0]->label());
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function testCreateMapperRequiresLabel(): void
    {
        $this->expectException(\Omeka\Api\Exception\ValidationException::class);

        $this->api()->create('mappers', [
            'o-mapper:mapping' => $this->getSampleIniMapping(),
        ]);
    }

    public function testCreateMapperWithEmptyMapping(): void
    {
        // Empty mapping should be allowed (for drafts).
        $mapper = $this->api()->create('mappers', [
            'o:label' => 'Empty Mapping',
            'o-mapper:mapping' => '',
        ])->getContent();

        $this->createdMapperIds[] = $mapper->id();
        $this->assertSame('Empty Mapping', $mapper->label());
        $this->assertEmpty($mapper->mapping());
    }

    // =========================================================================
    // Mapping Content
    // =========================================================================

    public function testMapperWithIniContent(): void
    {
        $iniMapping = $this->getSampleIniMapping();
        $mapper = $this->createMapper('INI Mapper', $iniMapping);

        $this->assertStringContainsString('[info]', $mapper->mapping());
        $this->assertStringContainsString('[maps]', $mapper->mapping());
    }

    public function testMapperWithXmlContent(): void
    {
        $xmlMapping = $this->getSampleXmlMapping();
        $mapper = $this->createMapper('XML Mapper', $xmlMapping);

        $this->assertStringContainsString('<mapping>', $mapper->mapping());
        $this->assertStringContainsString('</mapping>', $mapper->mapping());
    }

    // =========================================================================
    // Representation
    // =========================================================================

    public function testMapperRepresentationHasExpectedMethods(): void
    {
        $mapper = $this->createMapper('Representation Test', $this->getSampleIniMapping());

        $this->assertTrue(method_exists($mapper, 'id'));
        $this->assertTrue(method_exists($mapper, 'label'));
        $this->assertTrue(method_exists($mapper, 'mapping'));
        $this->assertTrue(method_exists($mapper, 'owner'));
        $this->assertTrue(method_exists($mapper, 'created'));
        $this->assertTrue(method_exists($mapper, 'modified'));
    }

    public function testMapperHasOwner(): void
    {
        $mapper = $this->createMapper('Owner Test', $this->getSampleIniMapping());

        $owner = $mapper->owner();
        $this->assertNotNull($owner);
        $this->assertSame('admin@example.com', $owner->email());
    }

    public function testMapperHasTimestamps(): void
    {
        $mapper = $this->createMapper('Timestamp Test', $this->getSampleIniMapping());

        $this->assertInstanceOf(\DateTime::class, $mapper->created());
        // Modified may be null initially.
    }

    // =========================================================================
    // JSON-LD Representation
    // =========================================================================

    public function testMapperJsonLdRepresentation(): void
    {
        $mapper = $this->createMapper('JSON-LD Test', $this->getSampleIniMapping());

        $jsonLd = $mapper->jsonSerialize();

        $this->assertIsArray($jsonLd);
        $this->assertArrayHasKey('@id', $jsonLd);
        $this->assertArrayHasKey('o:id', $jsonLd);
        $this->assertArrayHasKey('o:label', $jsonLd);
        $this->assertArrayHasKey('o-module-mapper:mapping', $jsonLd);
    }
}
