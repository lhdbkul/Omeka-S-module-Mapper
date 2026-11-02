<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\Mapper;
use MapperTest\MapperDbTestCase;

/**
 * Tests for Mapper::extractFromResource() — querier omeka.
 *
 * @covers \Mapper\Stdlib\Mapper::extractFromResource
 */
class ExtractFromResourceTest extends MapperDbTestCase
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

    public function testEmptyPath(): void
    {
        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $this->assertSame([], $this->mapper->extractFromResource($item, ''));
        $this->assertSame([], $this->mapper->extractFromResource($item, '   '));
    }

    public function testProperty(): void
    {
        $item = $this->createItem([
            'dcterms:title' => ['First', 'Second'],
        ]);
        $result = $this->mapper->extractFromResource($item, 'dcterms:title');
        $this->assertSame(['First', 'Second'], $result);
    }

    public function testPropertyMissingReturnsEmpty(): void
    {
        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $this->assertSame([], $this->mapper->extractFromResource($item, 'dcterms:creator'));
    }

    public function testMetaId(): void
    {
        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $result = $this->mapper->extractFromResource($item, 'o:id');
        $this->assertCount(1, $result);
        $this->assertSame($item->id(), $result[0]);
    }

    public function testMetaIsPublic(): void
    {
        $item = $this->createItem(['dcterms:title' => 'Pub']);
        $result = $this->mapper->extractFromResource($item, 'o:is_public');
        $this->assertSame([true], $result);
    }

    /**
     * @see https://gitlab.com/Daniel-KM/Omeka-S-module-Mapper/-/work_items/3
     */
    public function testMetaSiteUrl(): void
    {
        $site = $this->api()->create('sites', [
            'o:title' => 'Mapper site',
            'o:slug' => 'mapper-site-' . bin2hex(random_bytes(3)),
            'o:theme' => 'default',
            'o:is_public' => true,
        ])->getContent();
        $item = $this->api()->create('items', [
            'o:is_public' => true,
            'o:site' => [['o:id' => $site->id()]],
        ])->getContent();
        try {
            $result = $this->mapper->extractFromResource($item, 'o:site_url');
            $this->assertCount(1, $result);
            $this->assertMatchesRegularExpression('~^https?://.*/s/' . preg_quote($site->slug(), '~') . '/item/' . $item->id() . '$~', $result[0]);
        } finally {
            $this->api()->delete('items', $item->id());
            $this->api()->delete('sites', $site->id());
        }
    }

    public function testFilterByLanguage(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Bonjour', '@language' => 'fra'],
                ['type' => 'literal', '@value' => 'Hello',   '@language' => 'eng'],
                ['type' => 'literal', '@value' => 'Hola',    '@language' => 'spa'],
            ],
        ]);

        $result = $this->mapper->extractFromResource($item, "dcterms:title[@language='fra']");
        $this->assertSame(['Bonjour'], $result);

        $result = $this->mapper->extractFromResource($item, 'dcterms:title[@language="eng"]');
        $this->assertSame(['Hello'], $result);
    }

    public function testFilterByDatatype(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Plain'],
                ['type' => 'uri',     '@id'    => 'https://example.org', 'o:label' => 'Site'],
            ],
        ]);

        $literals = $this->mapper->extractFromResource($item, "dcterms:title[@datatype='literal']");
        $this->assertSame(['Plain'], $literals);

        $uris = $this->mapper->extractFromResource($item, "dcterms:title[@datatype='uri']");
        $this->assertSame(['https://example.org'], $uris);
    }

    public function testIndexedAndWildcardOnArray(): void
    {
        $item = $this->createItem([
            'dcterms:title' => ['One', 'Two', 'Three'],
        ]);

        $first = $this->mapper->extractFromResource($item, 'dcterms:title[0]');
        $this->assertSame(['One'], $first);

        $third = $this->mapper->extractFromResource($item, 'dcterms:title[2]');
        $this->assertSame(['Three'], $third);

        $all = $this->mapper->extractFromResource($item, 'dcterms:title[*]');
        $this->assertSame(['One', 'Two', 'Three'], $all);
    }

    public function testIndexedOutOfRangeReturnsEmpty(): void
    {
        $item = $this->createItem(['dcterms:title' => ['Only']]);
        $this->assertSame([], $this->mapper->extractFromResource($item, 'dcterms:title[9]'));
    }

    public function testTraversalOwnerEmail(): void
    {
        $item = $this->createItem(['dcterms:title' => 'Owned']);
        $result = $this->mapper->extractFromResource($item, 'o:owner/o:email');
        $this->assertCount(1, $result);
        $this->assertStringContainsString('@', $result[0]);
    }

    public function testValueSubAccessors(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Bonjour', '@language' => 'fra'],
            ],
        ]);

        $values = $this->mapper->extractFromResource($item, 'dcterms:title/@value');
        $this->assertSame(['Bonjour'], $values);

        $langs = $this->mapper->extractFromResource($item, 'dcterms:title/@language');
        $this->assertSame(['fra'], $langs);

        $types = $this->mapper->extractFromResource($item, 'dcterms:title/@datatype');
        $this->assertSame(['literal'], $types);
    }

    /**
     * The omeka metadata are read with the methods of the representations.
     */
    public function testMetaAccessors(): void
    {
        $item = $this->createItem(['dcterms:title' => 'Metadata']);
        $owner = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();

        $this->assertSame(['Metadata'], array_map('strval', $this->mapper->extractFromResource($item, 'o:title')));
        $this->assertSame([$owner->getEmail()], $this->mapper->extractFromResource($item, 'o:owner/o:email'));
        $this->assertCount(1, $this->mapper->extractFromResource($item, 'o:created'));
        $this->assertSame([], $this->mapper->extractFromResource($item, 'o:media'));
        $this->assertSame([], $this->mapper->extractFromResource($item, 'o:media_type'), 'An item has no media type.');
    }

    public function testUnknownMetaReturnsEmpty(): void
    {
        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $this->assertSame([], $this->mapper->extractFromResource($item, 'o:unknown_meta'));
    }
}
