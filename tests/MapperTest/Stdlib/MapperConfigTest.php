<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\MapperConfig;
use MapperTest\MapperDbTestCase;


/**
 * Tests for the MapperConfig class.
 *
 * Note: The MapperConfig expects file references for string inputs (INI/XML).
 * These tests use array format which is parsed directly.
 *
 * @covers \Mapper\Stdlib\MapperConfig
 */
class MapperConfigTest extends MapperDbTestCase
{

    protected MapperConfig $mapperConfig;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        // Get the MapperConfig service from the container.
        $this->mapperConfig = $this->getServiceLocator()->get('Mapper\MapperConfig');
    }

    /**
     * A file reference cannot get out of the mapping directories.
     */
    public function testFileReferenceOutsideOfMappingDirectoriesIsNotRead(): void
    {
        $mappingPaths = $this->getServiceLocator()->get('Mapper\MappingPaths');
        $userPath = $mappingPaths->userPath();
        @mkdir($userPath, 0775, true);
        // A valid mapping just outside of the user mapping directory.
        $filename = 'mapper_test_' . bin2hex(random_bytes(4)) . '.ini';
        $filepath = dirname($userPath) . '/' . $filename;
        file_put_contents($filepath, "[maps]\n~ = dcterms:title ~ outside");
        $moduleToFile = str_repeat('../', substr_count(trim($mappingPaths->modulePath(), '/'), '/') + 1) . ltrim($filepath, '/');
        try {
            foreach ([
                'user:../' . $filename,
                'module:' . $moduleToFile,
                $moduleToFile,
            ] as $reference) {
                $mapping = $this->mapperConfig->__invoke('outside', $reference);
                $this->assertTrue($this->mapperConfig->hasError('outside'), $reference);
                $this->assertStringNotContainsString('outside', serialize($mapping['maps'] ?? []), $reference);
            }
        } finally {
            @unlink($filepath);
        }
    }

    /**
     * A php mapping is included only when it is a file of the module.
     */
    public function testUserPhpMappingIsNotIncluded(): void
    {
        $userPath = $this->getServiceLocator()->get('Mapper\MappingPaths')->userPath();
        @mkdir($userPath, 0775, true);
        $filename = 'mapper_test_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($userPath . '/' . $filename, '<?php $GLOBALS[\'mapper_test_included\'] = true; return [\'maps\' => []];');
        unset($GLOBALS['mapper_test_included']);
        try {
            $this->mapperConfig->__invoke('user_php', 'user:' . $filename);
            $this->assertTrue($this->mapperConfig->hasError('user_php'));
            $this->assertArrayNotHasKey('mapper_test_included', $GLOBALS);
        } finally {
            @unlink($userPath . '/' . $filename);
        }
    }

    /**
     * The separator of an ini line is the first "=" outside of brackets and
     * quotes, so a "=" in the destination is kept.
     */
    public function testIniLineWithEqualsInDestination(): void
    {
        $mapping = $this->mapperConfig->__invoke('ini_equals', <<<'INI'
            [params]
            url = https://example.org/api?a=b
            query = "q={query}&rows=10"

            [maps]
            id = dcterms:identifier ~ https://example.org/?id={id}
            /record/datafield[@tag="200"]/subfield[@code="a"] = dcterms:title
            ~ = dcterms:description ~ a=b
            INI);

        $this->assertSame('https://example.org/api?a=b', $mapping['params']['url'] ?? null);
        $this->assertSame('q={query}&rows=10', $mapping['params']['query'] ?? null);

        $this->assertSame('id', $mapping['maps'][0]['from']['path'] ?? null);
        $this->assertSame('dcterms:identifier', $mapping['maps'][0]['to']['field'] ?? null);
        $this->assertSame('https://example.org/?id={id}', $mapping['maps'][0]['mod']['pattern'] ?? null);

        $this->assertSame('/record/datafield[@tag="200"]/subfield[@code="a"]', $mapping['maps'][1]['from']['path'] ?? null);
        $this->assertSame('dcterms:title', $mapping['maps'][1]['to']['field'] ?? null);

        $this->assertSame('dcterms:description', $mapping['maps'][2]['to']['field'] ?? null);
        $this->assertSame('a=b', $mapping['maps'][2]['mod']['pattern'] ?? null);
    }

    /**
     * A quoted custom vocab label with spaces is a single datatype.
     */
    public function testIniDestinationWithQuotedCustomVocab(): void
    {
        $mapping = $this->mapperConfig->__invoke('ini_customvocab', "[maps]\ntype = dcterms:type ^^customvocab:\"My Type List\" @fra");
        $to = $mapping['maps'][0]['to'];
        $this->assertSame('dcterms:type', $to['field']);
        $this->assertSame('fra', $to['language']);
        $this->assertCount(1, $to['datatype']);
        $this->assertStringStartsWith('customvocab:', $to['datatype'][0]);
    }

    /**
     * The base of a mapping may be a json or a php mapping, and a circular
     * inheritance through them is stopped.
     */
    public function testInheritanceFromJsonAndPhpMappings(): void
    {
        $mappingPaths = $this->getServiceLocator()->get('Mapper\MappingPaths');
        $userPath = $mappingPaths->userPath();
        @mkdir($userPath, 0775, true);
        $suffix = bin2hex(random_bytes(4));
        $json = "mapper_test_$suffix.json";
        $php = "mapper_test_$suffix.php";
        $cycle = "mapper_test_{$suffix}_cycle.ini";
        file_put_contents($userPath . '/' . $json, json_encode([
            'info' => ['label' => 'Json base', 'querier' => 'jsdot'],
            'maps' => [['from' => ['path' => 'title'], 'to' => ['field' => 'dcterms:title']]],
        ]));
        // Only the php files of the module are included.
        file_put_contents($mappingPaths->modulePath() . '/' . $php, "<?php\nreturn ['info' => ['label' => 'Php base'], 'maps' => [['from' => ['path' => 'date'], 'to' => ['field' => 'dcterms:date']]]];\n");
        // A json base that inherits from an ini that inherits from the json.
        file_put_contents($userPath . '/' . $cycle, "[info]\nmapper = user:$json\n\n[maps]\ncreator = dcterms:creator");
        $jsonCycle = "mapper_test_{$suffix}_cycle.json";
        file_put_contents($userPath . '/' . $jsonCycle, json_encode([
            'info' => ['mapper' => "user:$cycle"],
            'maps' => [['from' => ['path' => 'subject'], 'to' => ['field' => 'dcterms:subject']]],
        ]));
        file_put_contents($userPath . '/' . $json, json_encode([
            'info' => ['label' => 'Json base', 'querier' => 'jsdot', 'mapper' => "user:$jsonCycle"],
            'maps' => [['from' => ['path' => 'title'], 'to' => ['field' => 'dcterms:title']]],
        ]));
        try {
            $fields = fn (array $mapping) => array_column(array_column($mapping['maps'], 'to'), 'field');

            $mapping = $this->mapperConfig->__invoke('inherit_json', "[info]\nmapper = user:$json\n\n[maps]\nid = dcterms:identifier");
            $this->assertFalse($this->mapperConfig->hasError('inherit_json'));
            $this->assertSame('jsdot', $mapping['info']['querier']);
            $this->assertContains('dcterms:title', $fields($mapping));
            $this->assertContains('dcterms:identifier', $fields($mapping));

            $mapping = $this->mapperConfig->__invoke('inherit_php', "[info]\nmapper = module:$php\n\n[maps]\nid = dcterms:identifier");
            $this->assertFalse($this->mapperConfig->hasError('inherit_php'));
            $this->assertSame(['dcterms:date', 'dcterms:identifier'], $fields($mapping));
        } finally {
            foreach ([$json, $cycle, $jsonCycle] as $file) {
                @unlink($userPath . '/' . $file);
            }
            @unlink($mappingPaths->modulePath() . '/' . $php);
        }
    }

    /**
     * A mapping of the database is loaded by id or by label.
     */
    public function testDatabaseReferences(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $label = 'Mapper test ' . bin2hex(random_bytes(4));
        $mapper = $api->create('mappers', [
            'o:label' => $label,
            'o:mapping' => "[maps]\ntitle = dcterms:title",
        ])->getContent();
        try {
            foreach (['mapping:' . $mapper->id(), 'mapping:' . $label] as $reference) {
                $mapping = $this->mapperConfig->__invoke('db:' . $reference, $reference);
                $this->assertFalse($this->mapperConfig->hasError('db:' . $reference), $reference);
                $this->assertSame('dcterms:title', $mapping['maps'][0]['to']['field'] ?? null, $reference);
            }
            $this->mapperConfig->__invoke('db:missing', 'mapping:Missing ' . $label);
            $this->assertTrue($this->mapperConfig->hasError('db:missing'));
        } finally {
            $api->delete('mappers', $mapper->id());
        }
    }

    /**
     * A json mapping can be passed as content, and an invalid one is an error.
     */
    public function testJsonContent(): void
    {
        $mapping = $this->mapperConfig->__invoke('json_content', '{"info": {"label": "Json", "context": "ead"}, "maps": [{"from": {"path": "title"}, "to": {"field": "dcterms:title"}}]}');
        $this->assertFalse($this->mapperConfig->hasError('json_content'));
        $this->assertSame('Json', $mapping['info']['label']);
        $this->assertSame('ead', $mapping['info']['context']);
        $this->assertSame('dcterms:title', $mapping['maps'][0]['to']['field'] ?? null);

        $this->mapperConfig->__invoke('json_invalid', '{"info": {"label": "Json"}, "maps": [');
        $this->assertTrue($this->mapperConfig->hasError('json_invalid'));
    }

    /**
     * A mapping registered again with the same name and another content is
     * parsed again (for example one-line mappings built on the fly).
     */
    public function testSameNameWithAnotherContentIsParsedAgain(): void
    {
        $first = $this->mapperConfig->__invoke('same_name', "[maps]\n~ = dcterms:title ~ First");
        $second = $this->mapperConfig->__invoke('same_name', "[maps]\n~ = dcterms:title ~ Second");
        $this->assertSame('First', $first['maps'][0]['mod']['pattern'] ?? null);
        $this->assertSame('Second', $second['maps'][0]['mod']['pattern'] ?? null);

        // The same content is not parsed again.
        $this->mapperConfig->__invoke('same_name', "[maps]\n~ = dcterms:title ~ Second");
        $this->assertSame('Second', $this->mapperConfig->getMapping('same_name')['maps'][0]['mod']['pattern'] ?? null);
    }

    // Basic invoke tests

    public function testInvokeReturnsItself(): void
    {
        $result = $this->mapperConfig->__invoke();
        $this->assertSame($this->mapperConfig, $result);
    }

    public function testInvokeWithEmptyMapping(): void
    {
        $result = $this->mapperConfig->__invoke('empty', '');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('maps', $result);
    }

    public function testInvokeWithNullGeneratesName(): void
    {
        $result = $this->mapperConfig->__invoke(null, ['maps' => []]);
        $this->assertIsArray($result);
    }

    // getCurrentName tests

    public function testGetCurrentName(): void
    {
        $this->mapperConfig->__invoke('test_name', '');
        $this->assertSame('test_name', $this->mapperConfig->getCurrentName());
    }

    // getMapping tests

    public function testGetMappingNull(): void
    {
        $this->assertNull($this->mapperConfig->getMapping('nonexistent'));
    }

    public function testGetMappingByName(): void
    {
        $this->mapperConfig->__invoke('test', '');
        $mapping = $this->mapperConfig->getMapping('test');
        $this->assertIsArray($mapping);
    }

    // hasError tests

    public function testHasErrorTrue(): void
    {
        // Nonexistent mapping returns true.
        $this->assertTrue($this->mapperConfig->hasError('nonexistent'));
    }

    public function testHasErrorFalse(): void
    {
        $this->mapperConfig->__invoke('valid', '');
        $this->assertFalse($this->mapperConfig->hasError('valid'));
    }

    // getSection tests

    public function testGetSectionEmpty(): void
    {
        $this->mapperConfig->__invoke('test', '');
        $result = $this->mapperConfig->getSection('maps');
        $this->assertSame([], $result);
    }

    public function testGetSectionNonexistent(): void
    {
        $this->mapperConfig->__invoke('test', '');
        $result = $this->mapperConfig->getSection('nonexistent');
        $this->assertSame([], $result);
    }

    // Array parsing tests (arrays are parsed directly, unlike strings which expect file refs)

    public function testParseArrayWithInfo(): void
    {
        $input = [
            'info' => [
                'label' => 'Array Mapping',
                'from' => 'json',
                'to' => 'omeka',
            ],
            'maps' => [
                ['from' => 'title', 'to' => 'dcterms:title'],
            ],
        ];

        $result = $this->mapperConfig->__invoke('array_test', $input);
        $this->assertSame('Array Mapping', $result['info']['label']);
        $this->assertNotEmpty($result['maps']);
    }

    public function testParseArraySimpleList(): void
    {
        // Simple list of maps (like spreadsheet headers).
        $input = [
            'dcterms:title',
            'dcterms:creator',
            'dcterms:date',
        ];

        $result = $this->mapperConfig->__invoke('list_test', $input);
        $this->assertCount(3, $result['maps']);
        $this->assertSame('dcterms:title', $result['maps'][0]['to']['field']);
    }

    public function testParseArrayWithDefaultSection(): void
    {
        $input = [
            'info' => ['label' => 'Default Test'],
            'default' => [
                ['from' => '', 'to' => 'dcterms:type', 'mod' => ['raw' => 'Default Type']],
            ],
            'maps' => [
                ['from' => 'data.title', 'to' => 'dcterms:title'],
            ],
        ];

        $result = $this->mapperConfig->__invoke('default_test', $input);

        // Default section is deprecated and merged into maps.
        $this->assertEmpty($result['default'], 'Default section should be empty after merge');
        // Default maps should be prepended to maps section.
        $this->assertCount(2, $result['maps'], 'Maps should contain both default and regular maps');
        // First map should be the former default map.
        $this->assertSame('dcterms:type', $result['maps'][0]['to']['field']);
    }

    public function testParseArrayWithQuerier(): void
    {
        $input = [
            'info' => ['label' => 'Querier Test', 'querier' => 'jsdot'],
            'maps' => [
                ['from' => 'data.title', 'to' => 'dcterms:title'],
            ],
        ];

        $result = $this->mapperConfig->__invoke('querier_test', $input);
        $this->assertSame('jsdot', $result['info']['querier']);
    }

    // PHP file tests

    public function testParsePhpFile(): void
    {
        $fixturesPath = dirname(__DIR__, 2) . '/fixtures';
        $phpFile = $fixturesPath . '/php/simple.mapping.php';

        $this->assertFileExists($phpFile, 'PHP fixture file should exist');

        // Load via module: prefix simulation - use direct array loading.
        $data = include $phpFile;
        $result = $this->mapperConfig->__invoke('php_test', $data);

        $this->assertNotNull($result);
        $this->assertSame('Simple PHP Mapping', $result['info']['label']);
        $this->assertSame('jsdot', $result['info']['querier']);
        $this->assertNotEmpty($result['maps']);
    }

    public function testPhpFileMustReturnArray(): void
    {
        $data = include dirname(__DIR__, 2) . '/fixtures/php/simple.mapping.php';

        $this->assertIsArray($data);
        $this->assertArrayHasKey('info', $data);
        $this->assertArrayHasKey('maps', $data);
    }

    public function testPhpMappingHasMaps(): void
    {
        $data = include dirname(__DIR__, 2) . '/fixtures/php/simple.mapping.php';
        $result = $this->mapperConfig->__invoke('php_maps_test', $data);

        $this->assertCount(4, $result['maps']);

        // Check first map.
        $firstMap = $result['maps'][0];
        $this->assertSame('title', $firstMap['from']['path']);
        $this->assertSame('dcterms:title', $firstMap['to']['field']);
    }

    // normalizeMap tests

    public function testNormalizeMapEmpty(): void
    {
        $result = $this->mapperConfig->normalizeMap([]);
        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('to', $result);
        $this->assertArrayHasKey('mod', $result);
    }

    public function testNormalizeMapString(): void
    {
        $result = $this->mapperConfig->normalizeMap('dcterms:title');
        $this->assertSame('dcterms:title', $result['to']['field']);
    }

    public function testNormalizeMapStringWithPattern(): void
    {
        $result = $this->mapperConfig->normalizeMap('source = dcterms:title ~ {{ value|upper }}');
        $this->assertSame('source', $result['from']['path']);
        $this->assertSame('dcterms:title', $result['to']['field']);
        $this->assertContains('{{ value|upper }}', $result['mod']['filters']);
    }

    public function testNormalizeMapArray(): void
    {
        $input = [
            'from' => 'source_field',
            'to' => 'dcterms:title',
        ];
        $result = $this->mapperConfig->normalizeMap($input);
        $this->assertSame('source_field', $result['from']['path']);
        $this->assertSame('dcterms:title', $result['to']['field']);
    }

    public function testNormalizeMapArrayWithFullSpec(): void
    {
        $input = [
            'from' => ['path' => 'data.title', 'querier' => 'jsdot'],
            'to' => ['field' => 'dcterms:title', 'language' => 'en'],
            'mod' => ['prepend' => 'Title: '],
        ];
        $result = $this->mapperConfig->normalizeMap($input);
        $this->assertSame('data.title', $result['from']['path']);
        $this->assertSame('jsdot', $result['from']['querier']);
        $this->assertSame('en', $result['to']['language']);
        $this->assertSame('Title: ', $result['mod']['prepend']);
    }

    public function testNormalizeMapWithDatatype(): void
    {
        $result = $this->mapperConfig->normalizeMap('source = dcterms:source ^^uri');
        $this->assertContains('uri', $result['to']['datatype']);
    }

    public function testNormalizeMapWithLanguage(): void
    {
        $result = $this->mapperConfig->normalizeMap('title = dcterms:title @en');
        $this->assertSame('en', $result['to']['language']);
    }

    public function testNormalizeMapWithVisibility(): void
    {
        $result = $this->mapperConfig->normalizeMap('source = dcterms:description §private');
        $this->assertFalse($result['to']['is_public']);
    }

    public function testNormalizeMapWithPublicVisibility(): void
    {
        $result = $this->mapperConfig->normalizeMap('source = dcterms:description §public');
        $this->assertTrue($result['to']['is_public']);
    }

    public function testNormalizeMapMultipleDataTypes(): void
    {
        $result = $this->mapperConfig->normalizeMap('source = dcterms:identifier ^^uri ^^literal');
        $this->assertContains('uri', $result['to']['datatype']);
        $this->assertContains('literal', $result['to']['datatype']);
    }

    public function testNormalizeMapFieldSpecWithAll(): void
    {
        $result = $this->mapperConfig->normalizeMap('source = dcterms:title ^^literal @fr §public');
        $this->assertSame('dcterms:title', $result['to']['field']);
        $this->assertContains('literal', $result['to']['datatype']);
        $this->assertSame('fr', $result['to']['language']);
        $this->assertTrue($result['to']['is_public']);
    }

    public function testNormalizeMapWithRawValue(): void
    {
        $result = $this->mapperConfig->normalizeMap('~ = dcterms:type ~ "Static Type"');
        $this->assertArrayHasKey('mod', $result);
    }

    public function testNormalizeMapComplexPattern(): void
    {
        $result = $this->mapperConfig->normalizeMap('~ = dcterms:identifier ~ https://example.org/{{ value }}/item');
        $this->assertStringContainsString('https://example.org/', $result['mod']['pattern']);
    }

    // normalizeMaps tests

    public function testNormalizeMapsEmpty(): void
    {
        $result = $this->mapperConfig->normalizeMaps([]);
        $this->assertSame([], $result);
    }

    public function testNormalizeMapsMultiple(): void
    {
        $input = [
            'dcterms:title',
            'dcterms:creator',
        ];
        $result = $this->mapperConfig->normalizeMaps($input);
        $this->assertCount(2, $result);
    }

    // getSectionSetting tests

    public function testGetSectionSettingDefault(): void
    {
        $this->mapperConfig->__invoke('default_setting_test', '');
        $result = $this->mapperConfig->getSectionSetting('info', 'nonexistent', 'default_value');
        $this->assertSame('default_value', $result);
    }

    public function testGetSectionSettingFromArray(): void
    {
        $input = [
            'info' => [
                'label' => 'Test',
                'querier' => 'jsdot',
            ],
        ];
        $this->mapperConfig->__invoke('info_test', $input);
        $this->assertSame('Test', $this->mapperConfig->getSectionSetting('info', 'label'));
        $this->assertSame('jsdot', $this->mapperConfig->getSectionSetting('info', 'querier'));
    }

    // Caching tests

    public function testMappingIsCached(): void
    {
        $input = ['info' => ['label' => 'Cached']];

        // First call.
        $result1 = $this->mapperConfig->__invoke('cache_test', $input);

        // Second call should return cached.
        $result2 = $this->mapperConfig->__invoke('cache_test', null);

        $this->assertSame($result1, $result2);
    }

    public function testDifferentNamesAreSeparate(): void
    {
        $input1 = ['info' => ['label' => 'First']];
        $input2 = ['info' => ['label' => 'Second']];

        $this->mapperConfig->__invoke('name1', $input1);
        $this->mapperConfig->__invoke('name2', $input2);

        $this->assertSame('First', $this->mapperConfig->getMapping('name1')['info']['label']);
        $this->assertSame('Second', $this->mapperConfig->getMapping('name2')['info']['label']);
    }

    // XML map normalization test (using normalizeMap which can handle XML strings)

    public function testNormalizeMapXmlString(): void
    {
        $xmlMap = '<map><from xpath="//title"/><to field="dcterms:title"/></map>';
        $result = $this->mapperConfig->normalizeMap($xmlMap);
        $this->assertSame('dcterms:title', $result['to']['field']);
        $this->assertSame('xpath', $result['from']['querier']);
    }

    // XML include tests

    public function testXmlIncludeProcessing(): void
    {
        // Load mapping that uses includes.
        $result = $this->mapperConfig->__invoke('ead/ead.base.xml', 'module:ead/ead.base.xml');

        // The mapping should have maps from included files.
        $this->assertIsArray($result);
        $this->assertNotEmpty($result['maps'], 'Maps should be populated from included files');

        // Check that info section is present.
        $this->assertSame('Ead to Omeka', $result['info']['label']);
    }

    public function testXmlIncludeResolvesRelativePaths(): void
    {
        // Load mapping that uses relative includes.
        $result = $this->mapperConfig->__invoke('ead/ead.base.xml', 'module:ead/ead.base.xml');

        // The mapping should contain maps from both included files.
        // ead.presentation.xml has maps for /eadheader
        // ead.components.xml has maps for component resources
        $hasEadheaderMap = false;
        foreach ($result['maps'] as $map) {
            if (isset($map['from']['path']) && strpos($map['from']['path'], 'eadheader') !== false) {
                $hasEadheaderMap = true;
                break;
            }
        }
        $this->assertTrue($hasEadheaderMap, 'Should have maps from ead.presentation.xml');
    }

    // INI mapper inheritance tests

    public function testIniMapperInheritance(): void
    {
        // Load mapping that inherits from a base mapping via info.mapper.
        $result = $this->mapperConfig->__invoke(
            'content-dm/content-dm.unistra_collection-3.jsdot.ini',
            'module:content-dm/content-dm.unistra_collection-3.jsdot.ini'
        );

        // The mapping should have params from the base mapping.
        $this->assertIsArray($result);
        $this->assertNotEmpty($result['params'], 'Should have params from base mapping');
        $this->assertArrayHasKey('endpoint', $result['params']);
        $this->assertArrayHasKey('resources_root', $result['params']);

        // The mapping should have maps from the base mapping.
        $this->assertNotEmpty($result['maps'], 'Should have maps from base mapping');
    }

    public function testIniMapperInheritancePreservesQuerier(): void
    {
        // Load mapping that inherits from a base mapping.
        $result = $this->mapperConfig->__invoke(
            'content-dm/content-dm.unistra_collection-3.jsdot.ini',
            'module:content-dm/content-dm.unistra_collection-3.jsdot.ini'
        );

        // The querier from base should be inherited.
        $this->assertSame('jsdot', $result['info']['querier']);
    }

    public function testBaseMapperDoesNotInheritItself(): void
    {
        // Load the base mapping directly - it has mapper = content-dm/content-dm.base.jsdot
        // which points to itself, so it should not try to load itself.
        $result = $this->mapperConfig->__invoke(
            'content-dm/content-dm.base.jsdot.ini',
            'module:content-dm/content-dm.base.jsdot.ini'
        );

        // The mapping should load without error.
        $this->assertIsArray($result);
        $this->assertFalse($result['has_error']);
        $this->assertSame('jsdot', $result['info']['querier']);
    }

    // Static params evaluation tests

    public function testEvaluateStaticParamsWithRawParams(): void
    {
        $input = [
            'info' => ['label' => 'Static Params Test'],
            'params' => [
                'raw_param' => 'simple_value',
                'another_raw' => 'another_value',
            ],
        ];

        $this->mapperConfig->__invoke('static_test', $input);
        $this->mapperConfig->evaluateStaticParams(['url' => 'https://example.org']);

        $result = $this->mapperConfig->getMapping('static_test');
        // Raw params should remain unchanged.
        $this->assertSame('simple_value', $result['params']['raw_param']);
        $this->assertSame('another_value', $result['params']['another_raw']);
    }

    public function testEvaluateStaticParamsWithUrlVariable(): void
    {
        // Create an INI-style mapping with a pattern param.
        $ini = <<<INI
[info]
label = Pattern Params Test

[params]
endpoint = ~ {{ url|split('/api/', -1)|first }}

[maps]
title = dcterms:title
INI;
        $this->mapperConfig->__invoke('url_pattern_test', $ini);
        $this->mapperConfig->evaluateStaticParams(['url' => 'https://example.org/api/items']);

        $result = $this->mapperConfig->getMapping('url_pattern_test');
        // The endpoint should be evaluated from the url.
        $this->assertSame('https://example.org', $result['params']['endpoint']);
    }

    /**
     * The static params use the same filters as the maps, with arguments.
     */
    public function testEvaluateStaticParamsUsesFiltersWithArguments(): void
    {
        $this->mapperConfig->__invoke('url_filters_test', <<<'INI'
            [params]
            collection = ~ {{ url|trim('/')|split('/')|last|upper }}
            host = ~ {{ url|replace({'https://': ''})|split('/')|first }}
            INI);
        $this->mapperConfig->evaluateStaticParams(['url' => 'https://example.org/api/items/'], 'url_filters_test');

        $params = $this->mapperConfig->getMapping('url_filters_test')['params'];
        $this->assertSame('ITEMS', $params['collection']);
        $this->assertSame('example.org', $params['host']);
    }

    public function testEvaluateStaticParamsSkipsDynamicParams(): void
    {
        // Create mapping with a dynamic param (uses {{ page }}).
        $ini = <<<INI
[info]
label = Dynamic Params Test

[params]
endpoint = https://example.org
page_url = ~ {{ endpoint }}/page/{{ page }}

[maps]
title = dcterms:title
INI;
        $this->mapperConfig->__invoke('dynamic_test', $ini);
        $this->mapperConfig->evaluateStaticParams(['url' => 'https://example.org']);

        $result = $this->mapperConfig->getMapping('dynamic_test');
        // endpoint is static (raw), so unchanged.
        $this->assertSame('https://example.org', $result['params']['endpoint']);
        // page_url is dynamic (uses {{ page }}), so should remain as pattern array.
        $this->assertIsArray($result['params']['page_url']);
        $this->assertArrayHasKey('pattern', $result['params']['page_url']);
    }

    public function testEvaluateStaticParamsWithDependentParams(): void
    {
        // Params that reference other params should be evaluated in order.
        $ini = <<<INI
[info]
label = Dependent Params Test

[params]
base_url = https://example.org
endpoint = ~ {{ base_url }}/api

[maps]
title = dcterms:title
INI;
        $this->mapperConfig->__invoke('dependent_test', $ini);
        $this->mapperConfig->evaluateStaticParams([]);

        $result = $this->mapperConfig->getMapping('dependent_test');
        // base_url is raw, stays as-is.
        $this->assertSame('https://example.org', $result['params']['base_url']);
        // endpoint depends on base_url and should be evaluated.
        $this->assertSame('https://example.org/api', $result['params']['endpoint']);
    }

    // Param order verification tests

    public function testVerifyParamOrderValid(): void
    {
        $params = [
            'first' => 'value1',
            'second' => [
                'pattern' => '{{ first }}/suffix',
                'replace' => [],
                'filters' => ['{{ first }}'],
            ],
        ];

        $warnings = $this->mapperConfig->verifyParamOrder($params);
        $this->assertEmpty($warnings, 'Valid order should produce no warnings');
    }

    public function testVerifyParamOrderInvalid(): void
    {
        // 'second' references 'first', but 'first' is defined later.
        $params = [
            'second' => [
                'pattern' => '{{ first }}/suffix',
                'replace' => [],
                'filters' => ['{{ first }}'],
            ],
            'first' => 'value1',
        ];

        $warnings = $this->mapperConfig->verifyParamOrder($params);
        $this->assertNotEmpty($warnings, 'Invalid order should produce warnings');
        $this->assertStringContainsString('first', $warnings[0]);
        $this->assertStringContainsString('second', $warnings[0]);
    }

    public function testVerifyParamOrderIgnoresStaticVariables(): void
    {
        // Using static variables (url, filename) should not produce warnings.
        $params = [
            'endpoint' => [
                'pattern' => '{{ url|split("/", -1)|first }}',
                'replace' => [],
                'filters' => ['{{ url|split("/", -1)|first }}'],
            ],
        ];

        $warnings = $this->mapperConfig->verifyParamOrder($params);
        $this->assertEmpty($warnings, 'Static variables should not produce warnings');
    }

    public function testVerifyParamOrderIgnoresDynamicVariables(): void
    {
        // Using dynamic variables (page, value) should not produce warnings.
        $params = [
            'page_url' => [
                'pattern' => 'https://example.org/page/{{ page }}',
                'replace' => [],
                'filters' => ['{{ page }}'],
            ],
        ];

        $warnings = $this->mapperConfig->verifyParamOrder($params);
        $this->assertEmpty($warnings, 'Dynamic variables should not produce warnings');
    }

    // Static/Dynamic variable constants tests

    public function testStaticVariablesConstant(): void
    {
        $this->assertContains('url', MapperConfig::STATIC_VARIABLES);
        $this->assertContains('filename', MapperConfig::STATIC_VARIABLES);
    }

    public function testDynamicVariablesConstant(): void
    {
        $this->assertContains('page', MapperConfig::DYNAMIC_VARIABLES);
        $this->assertContains('value', MapperConfig::DYNAMIC_VARIABLES);
        $this->assertContains('url_resource', MapperConfig::DYNAMIC_VARIABLES);
    }

    // =========================================================================
    // INI Syntax Combination Tests
    // =========================================================================

    public function testIniMapSimpleExtraction(): void
    {
        $ini = <<<INI
[info]
label = Simple Extraction Test
querier = jsdot

[maps]
title = dcterms:title
metadata.creator = dcterms:creator
INI;
        $result = $this->mapperConfig->__invoke('simple_extract', $ini);

        $this->assertCount(2, $result['maps']);
        $this->assertSame('title', $result['maps'][0]['from']['path']);
        $this->assertSame('dcterms:title', $result['maps'][0]['to']['field']);
        $this->assertSame('metadata.creator', $result['maps'][1]['from']['path']);
        $this->assertSame('dcterms:creator', $result['maps'][1]['to']['field']);
    }

    public function testIniMapWithDatatype(): void
    {
        $ini = <<<INI
[info]
label = Datatype Test
querier = jsdot

[maps]
link = dcterms:source ^^uri
license = dcterms:license ^^uri ^^literal
INI;
        $result = $this->mapperConfig->__invoke('datatype_test', $ini);

        $this->assertContains('uri', $result['maps'][0]['to']['datatype']);
        $this->assertContains('uri', $result['maps'][1]['to']['datatype']);
        $this->assertContains('literal', $result['maps'][1]['to']['datatype']);
    }

    public function testIniMapWithLanguage(): void
    {
        $ini = <<<INI
[info]
label = Language Test
querier = jsdot

[maps]
title_en = dcterms:title @en
title_fr = dcterms:title @fra
INI;
        $result = $this->mapperConfig->__invoke('language_test', $ini);

        $this->assertSame('en', $result['maps'][0]['to']['language']);
        $this->assertSame('fra', $result['maps'][1]['to']['language']);
    }

    public function testIniMapWithVisibility(): void
    {
        $ini = <<<INI
[info]
label = Visibility Test
querier = jsdot

[maps]
public_field = dcterms:description §public
private_field = dcterms:rights §private
INI;
        $result = $this->mapperConfig->__invoke('visibility_test', $ini);

        $this->assertTrue($result['maps'][0]['to']['is_public']);
        $this->assertFalse($result['maps'][1]['to']['is_public']);
    }

    public function testIniMapWithPattern(): void
    {
        $ini = <<<INI
[info]
label = Pattern Test
querier = jsdot

[maps]
date = dcterms:date ~ {{ value|date('Y-m-d') }}
price = schema:price ~ {{ value }} EUR
INI;
        $result = $this->mapperConfig->__invoke('pattern_test', $ini);

        $this->assertStringContainsString("{{ value|date('Y-m-d') }}", $result['maps'][0]['mod']['pattern']);
        $this->assertStringContainsString('{{ value }} EUR', $result['maps'][1]['mod']['pattern']);
    }

    public function testIniMapAllQualifiersCombined(): void
    {
        $ini = <<<INI
[info]
label = Combined Qualifiers Test
querier = jsdot

[maps]
source = dcterms:title ^^literal @en §public ~ PREFIX {{ value }} SUFFIX
INI;
        $result = $this->mapperConfig->__invoke('combined_test', $ini);

        $map = $result['maps'][0];
        $this->assertSame('source', $map['from']['path']);
        $this->assertSame('dcterms:title', $map['to']['field']);
        $this->assertContains('literal', $map['to']['datatype']);
        $this->assertSame('en', $map['to']['language']);
        $this->assertTrue($map['to']['is_public']);
        $this->assertStringContainsString('PREFIX {{ value }} SUFFIX', $map['mod']['pattern']);
    }

    public function testIniDefaultMapWithTilde(): void
    {
        $ini = <<<INI
[info]
label = Default Map Tilde Test
querier = jsdot

[maps]
~ = o:resource_class ~ {{ "dctype:StillImage" }}
~ = dcterms:license ~ Public Domain
INI;
        $result = $this->mapperConfig->__invoke('default_tilde_test', $ini);

        $this->assertCount(2, $result['maps']);
        // Default maps have no source path.
        $this->assertEmpty($result['maps'][0]['from']['path'] ?? '');
        $this->assertSame('o:resource_class', $result['maps'][0]['to']['field']);
    }

    public function testIniDefaultMapWithQuotes(): void
    {
        $ini = <<<INI
[info]
label = Default Map Quotes Test
querier = jsdot

[maps]
o:resource_class = "dctype:StillImage"
dcterms:license = 'Public Domain'
INI;
        $result = $this->mapperConfig->__invoke('default_quotes_test', $ini);

        $this->assertCount(2, $result['maps']);
        $this->assertSame('o:resource_class', $result['maps'][0]['to']['field']);
        $this->assertSame('dctype:StillImage', $result['maps'][0]['mod']['raw']);
        $this->assertSame('dcterms:license', $result['maps'][1]['to']['field']);
        $this->assertSame('Public Domain', $result['maps'][1]['mod']['raw']);
    }

    public function testIniParamsRaw(): void
    {
        $ini = <<<INI
[info]
label = Params Raw Test
querier = jsdot

[params]
resources_root = items
fields = metadata

[maps]
title = dcterms:title
INI;
        $result = $this->mapperConfig->__invoke('params_raw_test', $ini);

        $this->assertSame('items', $result['params']['resources_root']);
        $this->assertSame('metadata', $result['params']['fields']);
    }

    public function testIniParamsPattern(): void
    {
        $ini = <<<INI
[info]
label = Params Pattern Test
querier = jsdot

[params]
base_url = https://example.org
endpoint = ~ {{ base_url }}/api

[maps]
title = dcterms:title
INI;
        $result = $this->mapperConfig->__invoke('params_pattern_test', $ini);

        $this->assertSame('https://example.org', $result['params']['base_url']);
        // Pattern param should be parsed.
        $this->assertIsArray($result['params']['endpoint']);
        $this->assertArrayHasKey('pattern', $result['params']['endpoint']);
    }

    public function testIniTables(): void
    {
        $ini = <<<INI
[info]
label = Tables Test
querier = jsdot

[tables]
gender.f = Female
gender.m = Male
status.1 = Active
status.2 = Inactive

[maps]
title = dcterms:title
INI;
        $result = $this->mapperConfig->__invoke('tables_test', $ini);

        $this->assertSame('Female', $result['tables']['gender']['f']);
        $this->assertSame('Male', $result['tables']['gender']['m']);
        $this->assertSame('Active', $result['tables']['status']['1']);
        $this->assertSame('Inactive', $result['tables']['status']['2']);
    }

    public function testIniXPathQuerier(): void
    {
        $ini = <<<INI
[info]
label = XPath Test
querier = xpath

[maps]
//title = dcterms:title
/record/metadata/@id = dcterms:identifier
//creator[1] = dcterms:creator
INI;
        $result = $this->mapperConfig->__invoke('xpath_test', $ini);

        $this->assertSame('xpath', $result['info']['querier']);
        $this->assertSame('//title', $result['maps'][0]['from']['path']);
        $this->assertSame('/record/metadata/@id', $result['maps'][1]['from']['path']);
    }

    public function testIniInheritance(): void
    {
        // Test that mapper key triggers inheritance (using existing base mapping).
        $result = $this->mapperConfig->__invoke(
            'content-dm/content-dm.unistra_collection-3.jsdot.ini',
            'module:content-dm/content-dm.unistra_collection-3.jsdot.ini'
        );

        // Should have inherited params and maps from base.
        $this->assertNotEmpty($result['params']);
        $this->assertArrayHasKey('resources_root', $result['params']);
        $this->assertSame('jsdot', $result['info']['querier']);
    }

    public function testIniMultipleSameDestination(): void
    {
        // Multiple maps to same destination (different sources).
        $ini = <<<INI
[info]
label = Multiple Same Destination Test
querier = jsdot

[maps]
title = dcterms:title
name = dcterms:title
heading = dcterms:title
INI;
        $result = $this->mapperConfig->__invoke('multi_dest_test', $ini);

        $this->assertCount(3, $result['maps']);
        // All map to same destination.
        $this->assertSame('dcterms:title', $result['maps'][0]['to']['field']);
        $this->assertSame('dcterms:title', $result['maps'][1]['to']['field']);
        $this->assertSame('dcterms:title', $result['maps'][2]['to']['field']);
        // But from different sources.
        $this->assertSame('title', $result['maps'][0]['from']['path']);
        $this->assertSame('name', $result['maps'][1]['from']['path']);
        $this->assertSame('heading', $result['maps'][2]['from']['path']);
    }

    public function testIniCommentedLines(): void
    {
        $ini = <<<INI
[info]
label = Comments Test
querier = jsdot

[maps]
; This is a comment
title = dcterms:title
; Another comment
;skipped = dcterms:skipped
description = dcterms:description
INI;
        $result = $this->mapperConfig->__invoke('comments_test', $ini);

        // Should only have 2 maps (commented one is skipped).
        $this->assertCount(2, $result['maps']);
        $this->assertSame('title', $result['maps'][0]['from']['path']);
        $this->assertSame('description', $result['maps'][1]['from']['path']);
    }

    public function testIniDefaultSectionIsMaps(): void
    {
        // When no section header is present, lines are treated as maps.
        $ini = <<<INI
title = dcterms:title
creator = dcterms:creator
INI;
        $result = $this->mapperConfig->__invoke('default_section_test', $ini);

        // Should have 2 maps even without [maps] section.
        $this->assertCount(2, $result['maps']);
        $this->assertSame('title', $result['maps'][0]['from']['path']);
        $this->assertSame('dcterms:title', $result['maps'][0]['to']['field']);
        $this->assertSame('creator', $result['maps'][1]['from']['path']);
        $this->assertSame('dcterms:creator', $result['maps'][1]['to']['field']);
    }

    public function testIniDefaultSectionWithPatterns(): void
    {
        // Default section should also support patterns.
        $ini = 'name = dcterms:title ~ {{ value|upper }}';
        $result = $this->mapperConfig->__invoke('default_pattern_test', $ini);

        $this->assertCount(1, $result['maps']);
        $this->assertSame('name', $result['maps'][0]['from']['path']);
        $this->assertArrayHasKey('pattern', $result['maps'][0]['mod']);
    }

    public function testIniDefaultSectionWithQualifiers(): void
    {
        // Default section should support all qualifiers.
        $ini = 'source = dcterms:identifier ^^uri @en §private';
        $result = $this->mapperConfig->__invoke('default_qualifiers_test', $ini);

        $this->assertCount(1, $result['maps']);
        $this->assertContains('uri', $result['maps'][0]['to']['datatype']);
        $this->assertSame('en', $result['maps'][0]['to']['language']);
        $this->assertFalse($result['maps'][0]['to']['is_public']);
    }

    public function testIniPatternWithPsr3Substitution(): void
    {
        $ini = <<<INI
[info]
label = PSR3 Substitution Test
querier = jsdot

[maps]
title = dcterms:source ~ https://example.org/items/{id}
record = dcterms:identifier ~ {collection}/{id}/page/{page}
INI;
        $result = $this->mapperConfig->__invoke('psr3_test', $ini);

        $this->assertStringContainsString('{id}', $result['maps'][0]['mod']['pattern']);
        $this->assertStringContainsString('{collection}', $result['maps'][1]['mod']['pattern']);
    }

    public function testIniPatternWithFilters(): void
    {
        $ini = <<<INI
[info]
label = Filters Test
querier = jsdot

[maps]
name = dcterms:title ~ {{ value|upper }}
text = dcterms:description ~ {{ value|trim|lower }}
INI;
        $result = $this->mapperConfig->__invoke('filters_test', $ini);

        $this->assertNotEmpty($result['maps'][0]['mod']['filters']);
        $this->assertContains('{{ value|upper }}', $result['maps'][0]['mod']['filters']);
    }

    public function testIniOmekaSpecialFields(): void
    {
        $ini = <<<INI
[info]
label = Omeka Fields Test
querier = jsdot

[maps]
~ = resource_name ~ {{ "o:Item" }}
~ = o:resource_class ~ {{ "dctype:StillImage" }}
contentType = o:media[o:media_type]
filename = o:media[o:filename]
INI;
        $result = $this->mapperConfig->__invoke('omeka_fields_test', $ini);

        $this->assertCount(4, $result['maps']);
        $this->assertSame('resource_name', $result['maps'][0]['to']['field']);
        $this->assertSame('o:resource_class', $result['maps'][1]['to']['field']);
        $this->assertSame('o:media[o:media_type]', $result['maps'][2]['to']['field']);
    }

    // =========================================================================
    // Four Formats Equivalence Tests
    // =========================================================================

    public function testJsonFormatFullStructure(): void
    {
        $json = <<<JSON
{
    "info": {
        "label": "JSON Full Test",
        "querier": "jsdot"
    },
    "params": {
        "endpoint": "https://example.org"
    },
    "maps": [
        {"from": {"path": "title"}, "to": {"field": "dcterms:title"}},
        {"from": {"path": "creator"}, "to": {"field": "dcterms:creator"}}
    ]
}
JSON;
        $result = $this->mapperConfig->__invoke('json_full_test', $json);

        $this->assertIsArray($result);
        $this->assertFalse($result['has_error'] ?? false);
        $this->assertSame('JSON Full Test', $result['info']['label']);
        $this->assertSame('jsdot', $result['info']['querier']);
        $this->assertSame('https://example.org', $result['params']['endpoint']);
        $this->assertCount(2, $result['maps']);
        $this->assertSame('title', $result['maps'][0]['from']['path']);
        $this->assertSame('dcterms:title', $result['maps'][0]['to']['field']);
    }

    public function testJsonFormatMapsOnly(): void
    {
        // JSON array of maps (format used by CopIdRef/BulkImport).
        $json = <<<JSON
[
    {"from": {"path": "name"}, "to": {"field": "foaf:name"}},
    {"from": {"path": "email"}, "to": {"field": "foaf:mbox"}}
]
JSON;
        $result = $this->mapperConfig->__invoke('json_maps_test', $json);

        $this->assertIsArray($result);
        $this->assertCount(2, $result['maps']);
        $this->assertSame('name', $result['maps'][0]['from']['path']);
        $this->assertSame('foaf:name', $result['maps'][0]['to']['field']);
    }

    public function testJsonFormatFromFile(): void
    {
        // Load actual JSON mapping file from idref/.
        $result = $this->mapperConfig->__invoke(
            'unimarc/unimarc.idref_personne.json',
            'module:unimarc/unimarc.idref_personne.json'
        );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['maps'], 'JSON file should produce maps');
    }

    public function testJsonFormatInvalidJson(): void
    {
        $invalidJson = '{"info": {"label": "Broken JSON"';

        $result = $this->mapperConfig->__invoke('json_invalid_test', $invalidJson);

        // Should return empty mapping with error flag.
        $this->assertIsArray($result);
        $this->assertTrue($result['has_error'] ?? false);
    }

    public function testPhpArrayFormatFullStructure(): void
    {
        $array = [
            'info' => [
                'label' => 'PHP Array Test',
                'querier' => 'jsdot',
            ],
            'params' => [
                'base_url' => 'https://example.org',
            ],
            'maps' => [
                ['from' => ['path' => 'title'], 'to' => ['field' => 'dcterms:title']],
                ['from' => ['path' => 'date'], 'to' => ['field' => 'dcterms:date']],
            ],
        ];

        $result = $this->mapperConfig->__invoke('php_array_test', $array);

        $this->assertIsArray($result);
        $this->assertFalse($result['has_error'] ?? false);
        $this->assertSame('PHP Array Test', $result['info']['label']);
        $this->assertSame('jsdot', $result['info']['querier']);
        $this->assertSame('https://example.org', $result['params']['base_url']);
        $this->assertCount(2, $result['maps']);
    }

    public function testPhpArrayFormatMapsOnly(): void
    {
        // Just an array of maps, without info/params structure.
        $array = [
            ['from' => ['path' => 'field1'], 'to' => ['field' => 'dcterms:subject']],
            ['from' => ['path' => 'field2'], 'to' => ['field' => 'dcterms:type']],
        ];

        $result = $this->mapperConfig->__invoke('php_maps_only_test', $array);

        $this->assertIsArray($result);
        $this->assertCount(2, $result['maps']);
        $this->assertSame('field1', $result['maps'][0]['from']['path']);
    }

    public function testFourFormatsEquivalence(): void
    {
        // Define the same mapping in all 4 formats.
        $ini = <<<INI
[info]
label = Equivalence Test
querier = jsdot

[maps]
title = dcterms:title
INI;

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mapping>
    <info>
        <label>Equivalence Test</label>
        <querier>jsdot</querier>
    </info>
    <map>
        <from xpath="title"/>
        <to field="dcterms:title"/>
    </map>
</mapping>
XML;

        $json = <<<JSON
{
    "info": {"label": "Equivalence Test", "querier": "jsdot"},
    "maps": [{"from": {"path": "title"}, "to": {"field": "dcterms:title"}}]
}
JSON;

        $array = [
            'info' => ['label' => 'Equivalence Test', 'querier' => 'jsdot'],
            'maps' => [['from' => ['path' => 'title'], 'to' => ['field' => 'dcterms:title']]],
        ];

        // Parse all 4 formats.
        $resultIni = $this->mapperConfig->__invoke('equiv_ini', $ini);
        $resultXml = $this->mapperConfig->__invoke('equiv_xml', $xml);
        $resultJson = $this->mapperConfig->__invoke('equiv_json', $json);
        $resultArray = $this->mapperConfig->__invoke('equiv_array', $array);

        // All should have the same essential structure.
        $this->assertSame('Equivalence Test', $resultIni['info']['label']);
        $this->assertSame('Equivalence Test', $resultXml['info']['label']);
        $this->assertSame('Equivalence Test', $resultJson['info']['label']);
        $this->assertSame('Equivalence Test', $resultArray['info']['label']);

        $this->assertSame('jsdot', $resultIni['info']['querier']);
        $this->assertSame('jsdot', $resultXml['info']['querier']);
        $this->assertSame('jsdot', $resultJson['info']['querier']);
        $this->assertSame('jsdot', $resultArray['info']['querier']);

        $this->assertCount(1, $resultIni['maps']);
        $this->assertCount(1, $resultXml['maps']);
        $this->assertCount(1, $resultJson['maps']);
        $this->assertCount(1, $resultArray['maps']);

        $this->assertSame('title', $resultIni['maps'][0]['from']['path']);
        $this->assertSame('title', $resultXml['maps'][0]['from']['path']);
        $this->assertSame('title', $resultJson['maps'][0]['from']['path']);
        $this->assertSame('title', $resultArray['maps'][0]['from']['path']);

        $this->assertSame('dcterms:title', $resultIni['maps'][0]['to']['field']);
        $this->assertSame('dcterms:title', $resultXml['maps'][0]['to']['field']);
        $this->assertSame('dcterms:title', $resultJson['maps'][0]['to']['field']);
        $this->assertSame('dcterms:title', $resultArray['maps'][0]['to']['field']);
    }

    // =========================================================================
    // Source qualifier transfer tests (issue #9, #23)
    // =========================================================================

    /**
     * When mapping params come from a form, the key is the column
     * header (e.g. "dcterms:isPartOf ^^resource") and the value is
     * the selected property term. The qualifiers from the header
     * must be transferred to the target.
     */
    public function testNormalizeMapsTransfersSourceDatatypeToTarget(): void
    {
        $maps = [
            'dcterms:isPartOf ^^resource' => ['dcterms:isPartOf'],
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        $this->assertCount(1, $result);
        $this->assertContains('resource', $result[0]['to']['datatype']);
    }

    public function testNormalizeMapsTransfersSourceLanguageToTarget(): void
    {
        $maps = [
            'dcterms:title @fr' => ['dcterms:title'],
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        $this->assertCount(1, $result);
        $this->assertSame('fr', $result[0]['to']['language']);
    }

    public function testNormalizeMapsTransfersSourceVisibilityToTarget(): void
    {
        $maps = [
            'dcterms:rights §private' => ['dcterms:rights'],
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['to']['is_public']);
    }

    public function testNormalizeMapsTransfersMultipleDatatypes(): void
    {
        $maps = [
            'isad:unitdate ^^numeric:interval ^^numeric:timestamp ^^literal' => ['isad:unitdate'],
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        $this->assertCount(1, $result);
        $datatypes = $result[0]['to']['datatype'];
        $this->assertContains('literal', $datatypes);
        // numeric:interval and numeric:timestamp may be normalized
        // or kept as-is depending on installed modules.
        $this->assertGreaterThanOrEqual(1, count($datatypes));
    }

    public function testNormalizeMapsTransfersAllQualifiers(): void
    {
        $maps = [
            'dcterms:subject ^^resource @en §private' => ['dcterms:subject'],
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        $this->assertCount(1, $result);
        $this->assertContains('resource', $result[0]['to']['datatype']);
        $this->assertSame('en', $result[0]['to']['language']);
        $this->assertFalse($result[0]['to']['is_public']);
    }

    public function testNormalizeMapsDoesNotOverrideExistingTargetDatatype(): void
    {
        $maps = [
            'dcterms:source ^^resource' => [
                ['from' => [], 'to' => ['field' => 'dcterms:source', 'datatype' => ['uri']]],
            ],
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        // Target already has uri, source qualifier should not override.
        $this->assertContains('uri', $result[0]['to']['datatype']);
        $this->assertNotContains('resource', $result[0]['to']['datatype'] ?? []);
    }

    public function testNormalizeMapsNoTransferForNumericIndex(): void
    {
        // When the index is numeric (array list), no transfer.
        $maps = [
            'dcterms:title',
            'dcterms:creator',
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        $this->assertCount(2, $result);
        $this->assertSame('dcterms:title', $result[0]['to']['field']);
    }

    public function testNormalizeMapsNoTransferForPlainStringIndex(): void
    {
        // String index without qualifiers: no transfer needed.
        $maps = [
            'dcterms:title' => ['dcterms:title'],
        ];
        $result = $this->mapperConfig->normalizeMaps($maps);

        $this->assertCount(1, $result);
        $this->assertSame('dcterms:title', $result[0]['to']['field']);
    }
}
