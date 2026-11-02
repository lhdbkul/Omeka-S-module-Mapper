<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\ProcessXslt;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the ProcessXslt class.
 *
 * @covers \Mapper\Stdlib\ProcessXslt
 */
class ProcessXsltTest extends AbstractHttpControllerTestCase
{
    protected ProcessXslt $processXslt;

    public function setUp(): void
    {
        parent::setUp();
        $this->processXslt = $this->getApplication()
            ->getServiceManager()
            ->get('Mapper\ProcessXslt');
    }

    // =========================================================================
    // Service Registration Tests
    // =========================================================================

    public function testServiceIsRegistered(): void
    {
        $services = $this->getApplication()->getServiceManager();
        $this->assertTrue($services->has('Mapper\ProcessXslt'));
    }

    public function testServiceClassIsRegistered(): void
    {
        $services = $this->getApplication()->getServiceManager();
        $this->assertTrue($services->has(ProcessXslt::class));
    }

    public function testServiceReturnsCorrectInstance(): void
    {
        $this->assertInstanceOf(ProcessXslt::class, $this->processXslt);
    }

    // =========================================================================
    // Capability Tests
    // =========================================================================

    public function testIsPhpXslAvailable(): void
    {
        // PHP XSL extension should be available in test environment.
        $this->assertTrue($this->processXslt->isPhpXslAvailable());
    }

    public function testHasExternalProcessorReturnsBool(): void
    {
        $result = $this->processXslt->hasExternalProcessor();
        $this->assertIsBool($result);
    }

    public function testGetTempDirReturnsString(): void
    {
        $tempDir = $this->processXslt->getTempDir();
        $this->assertIsString($tempDir);
        $this->assertDirectoryExists($tempDir);
    }

    // =========================================================================
    // processString Tests
    // =========================================================================

    public function testProcessStringWithInlineXsl(): void
    {
        $xml = '<?xml version="1.0"?><root><title>Test</title></root>';
        $xsl = <<<XSL
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="xml" indent="yes"/>
    <xsl:template match="/">
        <result><xsl:value-of select="//title"/></result>
    </xsl:template>
</xsl:stylesheet>
XSL;

        $result = $this->processXslt->processString($xml, $xsl);

        $this->assertIsString($result);
        $this->assertStringContainsString('Test', $result);
        $this->assertStringContainsString('<result>', $result);
    }

    public function testProcessStringWithFilePath(): void
    {
        $xml = '<?xml version="1.0"?><root><item>Content</item></root>';
        $xslPath = dirname(__DIR__, 3) . '/data/mapping/common/identity.xslt1.xsl';

        if (!file_exists($xslPath)) {
            $this->markTestSkipped('Identity XSL file not found.');
        }

        $result = $this->processXslt->processString($xml, $xslPath);

        $this->assertIsString($result);
        $this->assertStringContainsString('Content', $result);
    }

    public function testProcessStringWithParameters(): void
    {
        $xml = '<?xml version="1.0"?><root/>';
        $xsl = <<<XSL
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:param name="myParam" select="'default'"/>
    <xsl:output method="xml"/>
    <xsl:template match="/">
        <result><xsl:value-of select="\$myParam"/></result>
    </xsl:template>
</xsl:stylesheet>
XSL;

        $result = $this->processXslt->processString($xml, $xsl, ['myParam' => 'custom']);

        $this->assertStringContainsString('custom', $result);
    }

    public function testProcessStringThrowsOnEmptyXml(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('empty');

        $this->processXslt->processString('', '<xsl:stylesheet/>');
    }

    public function testProcessStringThrowsOnInvalidXml(): void
    {
        $this->expectException(\Exception::class);

        $this->processXslt->processString('not xml', '<xsl:stylesheet/>');
    }

    public function testProcessStringThrowsOnInvalidXsl(): void
    {
        $this->expectException(\Exception::class);

        $xml = '<?xml version="1.0"?><root/>';
        $this->processXslt->processString($xml, 'not xsl content');
    }

    // =========================================================================
    // process (file-based) Tests
    // =========================================================================

    public function testProcessWithFiles(): void
    {
        $tempDir = $this->processXslt->getTempDir();

        // Create temp XML file.
        $xmlPath = tempnam($tempDir, 'test_xml_');
        file_put_contents($xmlPath, '<?xml version="1.0"?><root><data>Value</data></root>');

        // Create temp XSL file.
        $xslPath = tempnam($tempDir, 'test_xsl_');
        $xsl = <<<XSL
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="xml"/>
    <xsl:template match="/"><output><xsl:value-of select="//data"/></output></xsl:template>
</xsl:stylesheet>
XSL;
        file_put_contents($xslPath, $xsl);

        try {
            $outputPath = $this->processXslt->process($xmlPath, $xslPath);

            $this->assertIsString($outputPath);
            $this->assertFileExists($outputPath);

            $content = file_get_contents($outputPath);
            $this->assertStringContainsString('Value', $content);

            // Cleanup output.
            @unlink($outputPath);
        } finally {
            @unlink($xmlPath);
            @unlink($xslPath);
        }
    }

    public function testProcessThrowsOnMissingInputFile(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not readable');

        // Use a valid stylesheet so that input file check is reached.
        $xslPath = dirname(__DIR__, 3) . '/data/mapping/common/identity.xslt1.xsl';
        if (!file_exists($xslPath)) {
            $this->markTestSkipped('Identity XSL file not found.');
        }

        $this->processXslt->process('/nonexistent/file.xml', $xslPath);
    }

    public function testProcessThrowsOnMissingStylesheet(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stylesheet not found');

        $tempDir = $this->processXslt->getTempDir();
        $xmlPath = tempnam($tempDir, 'test_');
        file_put_contents($xmlPath, '<?xml version="1.0"?><root/>');

        try {
            $this->processXslt->process($xmlPath, '/nonexistent/stylesheet.xsl');
        } finally {
            @unlink($xmlPath);
        }
    }

    // =========================================================================
    // Setter/Getter Tests
    // =========================================================================

    public function testSetAndGetCommand(): void
    {
        $original = $this->processXslt->getCommand();

        $this->processXslt->setCommand('saxon -s:%1$s -xsl:%2$s -o:%3$s');
        $this->assertSame('saxon -s:%1$s -xsl:%2$s -o:%3$s', $this->processXslt->getCommand());

        // Restore original.
        $this->processXslt->setCommand($original);
    }

    public function testSetAndGetTempDir(): void
    {
        $original = $this->processXslt->getTempDir();

        $this->processXslt->setTempDir('/tmp/custom');
        $this->assertSame('/tmp/custom', $this->processXslt->getTempDir());

        // Restore original.
        $this->processXslt->setTempDir($original);
    }

    // =========================================================================
    // Invocable Tests
    // =========================================================================

    public function testInvokeCallsProcess(): void
    {
        $tempDir = $this->processXslt->getTempDir();

        $xmlPath = tempnam($tempDir, 'test_xml_');
        file_put_contents($xmlPath, '<?xml version="1.0"?><root/>');

        $xslPath = tempnam($tempDir, 'test_xsl_');
        $xsl = '<?xml version="1.0"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><out/></xsl:template></xsl:stylesheet>';
        file_put_contents($xslPath, $xsl);

        try {
            $outputPath = ($this->processXslt)($xmlPath, $xslPath);

            $this->assertIsString($outputPath);
            $this->assertFileExists($outputPath);

            @unlink($outputPath);
        } finally {
            @unlink($xmlPath);
            @unlink($xslPath);
        }
    }

    // =========================================================================
    // External command
    // =========================================================================

    /**
     * Run an external command on a temporary xml file and restore the command.
     */
    protected function processWithCommand(string $command, int $timeout = 600): ?string
    {
        $original = $this->processXslt->getCommand();
        $originalTimeout = $this->processXslt->getTimeout();
        $input = tempnam(sys_get_temp_dir(), 'mapper_test_') . '.xml';
        file_put_contents($input, '<?xml version="1.0"?><root>Test</root>');
        $this->processXslt->setCommand($command)->setTimeout($timeout);
        try {
            return $this->processXslt->process($input, dirname(__DIR__, 3) . '/data/mapping/common/identity.xslt1.xsl');
        } finally {
            $this->processXslt->setCommand($original)->setTimeout($originalTimeout);
            @unlink($input);
        }
    }

    /**
     * Messages of the processor on stderr are not errors when it succeeds.
     */
    public function testExternalCommandWithMessagesSucceeds(): void
    {
        $output = $this->processWithCommand('cp %1$s %3$s; echo "Warning: noise" >&2');
        $this->assertFileExists($output);
        $this->assertStringContainsString('Test', file_get_contents($output));
        @unlink($output);
    }

    public function testExternalCommandFailureIsAnError(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('exit code 3');
        $this->processWithCommand('exit 3');
    }

    public function testExternalCommandIsStoppedAfterTimeout(): void
    {
        $start = microtime(true);
        try {
            $this->processWithCommand('sleep 30', 1);
            $this->fail('The command should be stopped.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('stopped after 1 seconds', $e->getMessage());
        }
        $this->assertLessThan(10, microtime(true) - $start);
    }

    public function testExternalCommandWithoutTimeout(): void
    {
        $output = $this->processWithCommand('sleep 1; cp %1$s %3$s', 0);
        $this->assertFileExists($output);
        @unlink($output);
    }

    /**
     * The limits are set from the settings of the config form, and an empty
     * value uses the default one.
     */
    public function testLimitsAreSetFromSettings(): void
    {
        $services = $this->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $settings->set('mapper_xslt_timeout', '0');
        $settings->set('mapper_download_max_size', '1024');
        $settings->set('mapper_download_timeout', '');
        try {
            $processXslt = $services->build(ProcessXslt::class);
            $this->assertSame(0, $processXslt->getTimeout());
            $this->assertSame(['max_size' => 1024 * 1048576, 'timeout' => 60], $processXslt->getDownloadLimits());
        } finally {
            foreach (['mapper_xslt_timeout', 'mapper_download_max_size', 'mapper_download_timeout'] as $name) {
                $settings->delete($name);
            }
        }
    }

    public function testConfigFormHasLimits(): void
    {
        $form = $this->getApplication()->getServiceManager()->get('FormElementManager')->get(\Mapper\Form\ConfigForm::class);
        foreach (['mapper_xslt_timeout', 'mapper_download_max_size', 'mapper_download_timeout'] as $name) {
            $this->assertTrue($form->has($name), $name);
        }
        $form->setData([
            'csrf' => $form->has('csrf') ? $form->get('csrf')->getValue() : null,
            'mapper_xslt_processor_mode' => 'auto',
            'mapper_xslt_timeout' => '',
            'mapper_download_max_size' => '0',
            'mapper_download_timeout' => '30',
        ]);
        $this->assertTrue($form->isValid(), json_encode($form->getMessages()));
    }
}
