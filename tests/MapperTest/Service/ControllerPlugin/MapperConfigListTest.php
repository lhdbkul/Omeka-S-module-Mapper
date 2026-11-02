<?php declare(strict_types=1);

namespace MapperTest\Service\ControllerPlugin;

use MapperTest\MapperDbTestCase;

/**
 * Listing of the mapping files.
 */
class MapperConfigListTest extends MapperDbTestCase
{
    protected $tempDir;

    protected $link;

    public function setUp(): void
    {
        parent::setUp();
        $config = $this->getServiceLocator()->get('Config');
        $userDir = ($config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')) . '/mapping';
        @mkdir($userDir, 0775, true);

        $this->tempDir = sys_get_temp_dir() . '/mapper_list_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/test.ini', "[maps]\n");
        $this->link = $userDir . '/' . basename($this->tempDir);
        symlink($this->tempDir, $this->link);
    }

    public function tearDown(): void
    {
        @unlink($this->link);
        @unlink($this->tempDir . '/test.ini');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * A mapping directory may be a symlink, for example in managed deployments.
     */
    public function testListFilesInDirectoryBehindSymlink(): void
    {
        $subDir = basename($this->link);
        $list = $this->getServiceLocator()->get('ControllerPluginManager')->get('mapperConfigList')
            ->listMappings([[$subDir => 'ini']]);
        $this->assertArrayHasKey('user:' . $subDir . '/test.ini', $list['user']['options']);
    }
}
