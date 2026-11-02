<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\Mapper;
use MapperTest\MapperDbTestCase;

/**
 * Tests for Mapper::serialize() postprocess chain.
 *
 * @covers \Mapper\Stdlib\Mapper::serialize
 */
class PostprocessTest extends MapperDbTestCase
{
    protected Mapper $mapper;

    /**
     * Copy of the fixtures in the user mapping directory, since the files
     * cannot be read outside of the mapping directories.
     */
    protected string $fixturesPath;

    public function setUp(): void
    {
        parent::setUp();
        $this->mapper = $this->getServiceLocator()->get('Mapper\Mapper');
        $this->fixturesPath = $this->getServiceLocator()->get('Mapper\MappingPaths')->userPath()
            . '/mapper_test_' . bin2hex(random_bytes(4));
        @mkdir($this->fixturesPath, 0775, true);
        foreach (glob(dirname(__DIR__, 2) . '/fixtures/xsl/*') as $file) {
            copy($file, $this->fixturesPath . '/' . basename($file));
        }
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesPath . '/*'));
        @rmdir($this->fixturesPath);
        $this->cleanupResources();
        parent::tearDown();
    }

    protected function fixturesPath(): string
    {
        return $this->fixturesPath;
    }

    protected function buildMappingXml(string $postprocessRef, string $field): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mapping>
    <info>
        <label>postprocess test</label>
        <from>resources</from>
        <to>test</to>
        <querier>omeka</querier>
        <postprocess>{$postprocessRef}</postprocess>
    </info>
    <params>
        <param name="xmlns.dc">http://purl.org/dc/elements/1.1/</param>
    </params>
    <maps>
        <map>
            <from path="dcterms:title"/>
            <to field="{$field}"/>
        </map>
    </maps>
</mapping>
XML;
    }

    public function testIdentityXslIsNoOp(): void
    {
        $ref = $this->fixturesPath() . '/identity.xsl';
        $this->mapper->setMapping('postprocess_identity', $this->buildMappingXml($ref, '/dc:title'));

        $item = $this->createItem(['dcterms:title' => 'Hello']);
        $doc = $this->mapper->serialize($item);

        $this->assertInstanceOf(\DOMDocument::class, $doc);
        $titles = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title');
        $this->assertSame(1, $titles->length);
        $this->assertSame('Hello', $titles->item(0)->textContent);
    }

    public function testRenameXslChangesElement(): void
    {
        $ref = $this->fixturesPath() . '/rename_title.xsl';
        $this->mapper->setMapping('postprocess_rename', $this->buildMappingXml($ref, '/dc:title'));

        $item = $this->createItem(['dcterms:title' => 'Le titre']);
        $doc = $this->mapper->serialize($item);

        $this->assertInstanceOf(\DOMDocument::class, $doc);

        // dc:title should be renamed to mods:title.
        $dcTitles = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title');
        $this->assertSame(0, $dcTitles->length, 'dc:title should be replaced by mods:title');

        $modsTitles = $doc->getElementsByTagNameNS('http://www.loc.gov/mods/v3', 'title');
        $this->assertSame(1, $modsTitles->length);
        $this->assertSame('Le titre', $modsTitles->item(0)->textContent);
    }

    /**
     * The postprocess is kept for the mappings set as array or json.
     */
    public function testRenameXslWithArrayAndJsonMappings(): void
    {
        $mapping = [
            'info' => [
                'label' => 'postprocess array',
                'querier' => 'omeka',
                'postprocess' => $this->fixturesPath() . '/rename_title.xsl',
            ],
            'params' => [
                'xmlns.dc' => 'http://purl.org/dc/elements/1.1/',
            ],
            'maps' => [
                ['from' => ['path' => 'dcterms:title'], 'to' => ['field' => '/dc:title']],
            ],
        ];
        $item = $this->createItem(['dcterms:title' => 'Le titre']);
        foreach (['array' => $mapping, 'json' => json_encode($mapping)] as $type => $mappingOrRef) {
            $this->mapper->setMapping('postprocess_rename_' . $type, $mappingOrRef);
            $doc = $this->mapper->serialize($item);
            $modsTitles = $doc->getElementsByTagNameNS('http://www.loc.gov/mods/v3', 'title');
            $this->assertSame(1, $modsTitles->length, $type);
        }
    }

    public function testMissingXslReturnsOriginalDom(): void
    {
        $ref = $this->fixturesPath() . '/__does_not_exist__.xsl';
        $this->mapper->setMapping('postprocess_missing', $this->buildMappingXml($ref, '/dc:title'));

        $item = $this->createItem(['dcterms:title' => 'Stay']);
        $doc = $this->mapper->serialize($item);

        // Failure mode: applyPostprocess returns the original DOM unchanged.
        $this->assertInstanceOf(\DOMDocument::class, $doc);
        $titles = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title');
        $this->assertSame(1, $titles->length);
        $this->assertSame('Stay', $titles->item(0)->textContent);
    }

    /**
     * A postprocess outside of the mapping directories is not read.
     */
    public function testPostprocessOutsideOfMappingDirectoriesIsSkipped(): void
    {
        $ref = dirname(__DIR__, 2) . '/fixtures/xsl/rename_title.xsl';
        $this->assertFileExists($ref);
        $this->mapper->setMapping('postprocess_outside', $this->buildMappingXml($ref, '/dc:title'));

        $item = $this->createItem(['dcterms:title' => 'Stay']);
        $doc = $this->mapper->serialize($item);

        $titles = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title');
        $this->assertSame(1, $titles->length);
    }

    /**
     * A Twig-XML template outside of the mapping directories is not read, so
     * its content cannot be output.
     */
    public function testTwigXmlTemplateOutsideOfMappingDirectoriesIsNotRead(): void
    {
        $template = sys_get_temp_dir() . '/mapper_test_' . bin2hex(random_bytes(4)) . '.twig.xml';
        file_put_contents($template, '<secret>outside</secret>');
        try {
            $this->mapper->setMapping('postprocess_twig_outside', $this->buildMappingXml($template, '/dc:title'));
            $item = $this->createItem(['dcterms:title' => 'Stay']);
            $doc = $this->mapper->serialize($item);
            $this->assertInstanceOf(\DOMDocument::class, $doc);
            $this->assertStringNotContainsString('outside', $doc->saveXML());
        } finally {
            @unlink($template);
        }
    }

    public function testOmekaToModsMappingFile(): void
    {
        $mappingFile = dirname(dirname(dirname(__DIR__))) . '/data/mapping/mods/omeka_to_mods.xml';
        $this->assertFileExists($mappingFile);

        $this->mapper->setMapping('omeka_to_mods', file_get_contents($mappingFile));

        $item = $this->createItem([
            'dcterms:title' => 'Le titre',
            'dcterms:creator' => 'Berthereau, Daniel',
            'dcterms:date' => '2026',
            'dcterms:publisher' => 'Sempia',
            'dcterms:type' => 'StillImage',
            'dcterms:rights' => 'CC-BY-SA',
        ]);

        $doc = $this->mapper->serialize($item);
        $this->assertInstanceOf(\DOMDocument::class, $doc);

        $modsNs = 'http://www.loc.gov/mods/v3';

        $titles = $doc->getElementsByTagNameNS($modsNs, 'title');
        $this->assertSame(1, $titles->length);
        $this->assertSame('Le titre', $titles->item(0)->textContent);

        $names = $doc->getElementsByTagNameNS($modsNs, 'name');
        $this->assertSame(1, $names->length);
        $nameParts = $doc->getElementsByTagNameNS($modsNs, 'namePart');
        $this->assertSame('Berthereau, Daniel', $nameParts->item(0)->textContent);

        $originInfos = $doc->getElementsByTagNameNS($modsNs, 'originInfo');
        $this->assertSame(1, $originInfos->length);
        $publishers = $doc->getElementsByTagNameNS($modsNs, 'publisher');
        $this->assertSame('Sempia', $publishers->item(0)->textContent);
        $dates = $doc->getElementsByTagNameNS($modsNs, 'dateIssued');
        $this->assertSame('2026', $dates->item(0)->textContent);

        $accessConditions = $doc->getElementsByTagNameNS($modsNs, 'accessCondition');
        $this->assertSame('CC-BY-SA', $accessConditions->item(0)->textContent);

        // Pivot DC must not leak in the final MODS output.
        $dcTitles = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title');
        $this->assertSame(0, $dcTitles->length);
    }

    public function testTwigXmlPostprocessRoutesToTwigEngine(): void
    {
        $mappingFile = dirname(dirname(dirname(__DIR__))) . '/data/mapping/dublin-core/omeka_to_oai_dc_twig.xml';
        $this->assertFileExists($mappingFile);

        $this->mapper->setMapping('postprocess_twig', file_get_contents($mappingFile));

        $item = $this->createItem([
            'dcterms:title'   => ['Twig title 1', 'Twig title 2'],
            'dcterms:creator' => 'Berthereau, Daniel',
            'dcterms:rights'  => 'CC-BY & SA',
        ]);

        $doc = $this->mapper->serialize($item);
        $this->assertInstanceOf(\DOMDocument::class, $doc);

        $oaiDcNs = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
        $dcNs = 'http://purl.org/dc/elements/1.1/';

        $roots = $doc->getElementsByTagNameNS($oaiDcNs, 'dc');
        $this->assertSame(1, $roots->length);

        $titles = $doc->getElementsByTagNameNS($dcNs, 'title');
        $this->assertSame(2, $titles->length);
        $this->assertSame('Twig title 1', $titles->item(0)->textContent);
        $this->assertSame('Twig title 2', $titles->item(1)->textContent);

        $creators = $doc->getElementsByTagNameNS($dcNs, 'creator');
        $this->assertSame(1, $creators->length);
        $this->assertSame('Berthereau, Daniel', $creators->item(0)->textContent);

        // Auto-escape kept the rights value safe through the template.
        $rights = $doc->getElementsByTagNameNS($dcNs, 'rights');
        $this->assertSame('CC-BY & SA', $rights->item(0)->textContent);
    }

    public function testNoPostprocessLeavesDomUnchanged(): void
    {
        // Plain mapping without <postprocess>: must return the native DOM.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<mapping>
    <info>
        <label>no postprocess</label>
        <from>resources</from>
        <to>test</to>
        <querier>omeka</querier>
    </info>
    <params>
        <param name="xmlns.dc">http://purl.org/dc/elements/1.1/</param>
    </params>
    <maps>
        <map>
            <from path="dcterms:title"/>
            <to field="/dc:title"/>
        </map>
    </maps>
</mapping>
XML;
        $this->mapper->setMapping('postprocess_none', $xml);

        $item = $this->createItem(['dcterms:title' => 'Plain']);
        $doc = $this->mapper->serialize($item);

        $this->assertInstanceOf(\DOMDocument::class, $doc);
        $titles = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title');
        $this->assertSame('Plain', $titles->item(0)->textContent);
    }
}
