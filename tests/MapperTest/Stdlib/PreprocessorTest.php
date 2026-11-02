<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\Preprocessor;
use Mapper\Stdlib\ProcessXslt;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the Preprocessor class.
 *
 * @covers \Mapper\Stdlib\Preprocessor
 */
class PreprocessorTest extends AbstractHttpControllerTestCase
{
    protected Preprocessor $preprocessor;
    protected string $mappingPath;

    public function setUp(): void
    {
        parent::setUp();
        $this->preprocessor = $this->getApplication()
            ->getServiceManager()
            ->get('Mapper\Preprocessor');
        $this->mappingPath = dirname(__DIR__, 3) . '/data/mapping';
    }

    // =========================================================================
    // Service Registration Tests
    // =========================================================================

    public function testServiceIsRegistered(): void
    {
        $services = $this->getApplication()->getServiceManager();
        $this->assertTrue($services->has('Mapper\Preprocessor'));
    }

    public function testServiceClassIsRegistered(): void
    {
        $services = $this->getApplication()->getServiceManager();
        $this->assertTrue($services->has(Preprocessor::class));
    }

    public function testServiceReturnsCorrectInstance(): void
    {
        $this->assertInstanceOf(Preprocessor::class, $this->preprocessor);
    }

    // =========================================================================
    // Capability Tests
    // =========================================================================

    public function testIsXslAvailable(): void
    {
        $this->assertTrue($this->preprocessor->isXslAvailable());
    }

    public function testHasExternalProcessorReturnsBool(): void
    {
        $result = $this->preprocessor->hasExternalProcessor();
        $this->assertIsBool($result);
    }

    public function testGetBasePath(): void
    {
        $basePath = $this->preprocessor->getBasePath();
        $this->assertIsString($basePath);
        $this->assertDirectoryExists($basePath);
    }

    // =========================================================================
    // process() with Empty Preprocess Tests
    // =========================================================================

    public function testProcessWithEmptyPreprocessReturnsContent(): void
    {
        $content = '<root><data>Test</data></root>';
        $result = $this->preprocessor->process($content, []);

        $this->assertSame($content, $result);
    }

    public function testProcessWithNullPreprocessElementsSkipsThem(): void
    {
        $content = '<root><data>Test</data></root>';
        $result = $this->preprocessor->process($content, [null, '', 0]);

        $this->assertSame($content, $result);
    }

    // =========================================================================
    // process() with XSL File Reference Tests
    // =========================================================================

    public function testProcessWithModuleXslReference(): void
    {
        $content = '<?xml version="1.0"?><root><item>Test</item></root>';

        // Use identity transform.
        $result = $this->preprocessor->process(
            $content,
            ['module:common/identity.xslt1.xsl']
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('Test', $result);
    }

    /**
     * The user files are in files/mapping, even when the base path of the
     * local store is not set, like in the default config.
     *
     * @see https://gitlab.com/Daniel-KM/Omeka-S-module-Mapper/-/work_items/3
     */
    public function testProcessWithUserXslReference(): void
    {
        $config = $this->getApplication()->getServiceManager()->get('Config');
        $userBasePath = ($config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')) . '/mapping';
        $this->assertSame($userBasePath, $this->preprocessor->getUserBasePath());

        $filename = 'mapper_test_' . bin2hex(random_bytes(4)) . '.xsl';
        @mkdir($userBasePath, 0775, true);
        copy($this->mappingPath . '/common/identity.xslt1.xsl', $userBasePath . '/' . $filename);
        try {
            $result = $this->preprocessor->process('<?xml version="1.0"?><root><item>Test</item></root>', ['user:' . $filename]);
        } finally {
            @unlink($userBasePath . '/' . $filename);
        }

        $this->assertIsString($result);
        $this->assertStringContainsString('Test', $result);
    }

    public function testProcessWithRelativePathInContext(): void
    {
        $content = '<?xml version="1.0"?><root><data>Value</data></root>';

        // Should find identity.xslt1.xsl in common/ directory.
        $result = $this->preprocessor->process(
            $content,
            ['identity.xslt1.xsl'],
            [],
            'common'
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('Value', $result);
    }

    public function testProcessWithAbsolutePath(): void
    {
        $xslPath = $this->mappingPath . '/common/identity.xslt1.xsl';
        if (!file_exists($xslPath)) {
            $this->markTestSkipped('Identity XSL file not found.');
        }

        $content = '<?xml version="1.0"?><root><element>Content</element></root>';

        $result = $this->preprocessor->process($content, [$xslPath]);

        $this->assertIsString($result);
        $this->assertStringContainsString('Content', $result);
    }

    // =========================================================================
    // process() with XSL Content Tests
    // =========================================================================

    public function testProcessWithInlineXslContent(): void
    {
        $content = '<?xml version="1.0"?><root><title>Original</title></root>';

        $xsl = <<<XSL
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="xml"/>
    <xsl:template match="/">
        <transformed><xsl:value-of select="//title"/></transformed>
    </xsl:template>
</xsl:stylesheet>
XSL;

        // Write XSL to a file of the user mapping directory and use the
        // absolute path.
        $userBasePath = $this->preprocessor->getUserBasePath();
        @mkdir($userBasePath, 0775, true);
        $xslPath = $userBasePath . '/mapper_test_' . bin2hex(random_bytes(4)) . '.xsl';
        file_put_contents($xslPath, $xsl);

        try {
            $result = $this->preprocessor->process($content, [$xslPath]);

            $this->assertIsString($result);
            $this->assertStringContainsString('Original', $result);
            $this->assertStringContainsString('<transformed>', $result);
        } finally {
            @unlink($xslPath);
        }
    }

    // =========================================================================
    // process() with Multiple Transformations Tests
    // =========================================================================

    public function testProcessWithMultipleTransformations(): void
    {
        $content = '<?xml version="1.0"?><root><data>Test</data></root>';

        // Apply identity transform twice (should produce same result).
        $result = $this->preprocessor->process(
            $content,
            [
                'module:common/identity.xslt1.xsl',
                'module:common/identity.xslt1.xsl',
            ]
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('Test', $result);
    }

    // =========================================================================
    // process() Error Handling Tests
    // =========================================================================

    public function testProcessReturnsNullOnMissingTransformation(): void
    {
        $content = '<root/>';

        $result = $this->preprocessor->process(
            $content,
            ['module:nonexistent/missing.xsl']
        );

        $this->assertNull($result);
    }

    public function testProcessReturnsNullOnInvalidXml(): void
    {
        $content = 'not valid xml';

        $result = $this->preprocessor->process(
            $content,
            ['module:common/identity.xslt1.xsl']
        );

        $this->assertNull($result);
    }

    // =========================================================================
    // Invocable Tests
    // =========================================================================

    public function testInvokeCallsProcess(): void
    {
        $content = '<?xml version="1.0"?><root><item>Test</item></root>';

        $result = ($this->preprocessor)(
            $content,
            ['module:common/identity.xslt1.xsl']
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('Test', $result);
    }

    public function testInvokeWithEmptyPreprocessReturnsContent(): void
    {
        $content = '<root>Content</root>';
        $result = ($this->preprocessor)($content, []);

        $this->assertSame($content, $result);
    }

    // =========================================================================
    // Paths
    // =========================================================================

    public function testGetUserBasePath(): void
    {
        $config = $this->getApplication()->getServiceManager()->get('Config');
        $userBasePath = ($config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')) . '/mapping';
        $this->assertSame($userBasePath, $this->preprocessor->getUserBasePath());
    }

    /**
     * A transformation cannot be read outside of the mapping directories.
     */
    public function testProcessWithReferenceOutsideOfMappingDirectories(): void
    {
        $content = '<?xml version="1.0"?><root><item>Test</item></root>';
        $outside = sys_get_temp_dir() . '/mapper_test_' . bin2hex(random_bytes(4)) . '.xsl';
        copy($this->mappingPath . '/common/identity.xslt1.xsl', $outside);
        try {
            foreach ([$outside, 'module:../../../../' . ltrim($outside, '/'), 'user:../../' . basename($outside)] as $reference) {
                $this->assertNull($this->preprocessor->process($content, [$reference]), $reference);
            }
        } finally {
            @unlink($outside);
        }
    }

    /**
     * A transformation of the database is applied by label.
     */
    public function testProcessWithDatabaseReference(): void
    {
        $services = $this->getApplication()->getServiceManager();
        $auth = $services->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();

        $api = $services->get('Omeka\ApiManager');
        $label = 'mapper_test_' . bin2hex(random_bytes(4)) . '.xsl';
        $mapper = $api->create('mappers', [
            'o:label' => $label,
            'o:mapping' => file_get_contents($this->mappingPath . '/common/identity.xslt1.xsl'),
        ])->getContent();
        try {
            $result = $this->preprocessor->process('<?xml version="1.0"?><root><item>Test</item></root>', ['mapping:' . $label]);
            $this->assertIsString($result);
            $this->assertStringContainsString('Test', $result);
            $this->assertNull($this->preprocessor->process('<root/>', ['mapping:missing_' . $label]));
        } finally {
            $api->delete('mappers', $mapper->id());
            $auth->clearIdentity();
        }
    }
}
