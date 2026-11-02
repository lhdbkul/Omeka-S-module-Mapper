<?php declare(strict_types=1);

namespace MapperTest\Stdlib;

use Mapper\Stdlib\MappingPaths;
use PHPUnit\Framework\TestCase;

/**
 * The references of mapping files cannot get out of the mapping directories.
 */
class MappingPathsTest extends TestCase
{
    protected string $root;

    protected MappingPaths $mappingPaths;

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mapper_paths_' . bin2hex(random_bytes(4));
        foreach (['module/ead', 'module/common', 'user/sub', 'outside'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
        file_put_contents($this->root . '/module/ead/file.xsl', 'ead');
        file_put_contents($this->root . '/module/common/file.xsl', 'common');
        file_put_contents($this->root . '/module/root.xsl', 'root');
        file_put_contents($this->root . '/user/sub/user.xsl', 'user');
        file_put_contents($this->root . '/outside/secret.ini', 'secret');
        symlink($this->root . '/outside/secret.ini', $this->root . '/user/link.ini');
        $this->mappingPaths = new MappingPaths($this->root . '/module', $this->root . '/user/');
    }

    public function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testPrefixedReferences(): void
    {
        $this->assertSame($this->root . '/module/ead/file.xsl', $this->mappingPaths->resolve('module:ead/file.xsl'));
        $this->assertSame($this->root . '/user/sub/user.xsl', $this->mappingPaths->resolve('user:sub/user.xsl'));
        $this->assertSame($this->root . '/user', $this->mappingPaths->userPath());
    }

    public function testRelativeReferenceSearchesContextThenCommonThenRoot(): void
    {
        $this->assertSame($this->root . '/module/ead/file.xsl', $this->mappingPaths->resolve('file.xsl', 'ead'));
        $this->assertSame($this->root . '/module/common/file.xsl', $this->mappingPaths->resolve('file.xsl'));
        $this->assertSame($this->root . '/module/root.xsl', $this->mappingPaths->resolve('root.xsl', 'ead'));
    }

    public function testAbsolutePathInsideAllowedDirectories(): void
    {
        $this->assertSame($this->root . '/module/root.xsl', $this->mappingPaths->resolve($this->root . '/module/root.xsl'));
        $this->assertSame($this->root . '/user/sub/user.xsl', $this->mappingPaths->resolve($this->root . '/user/sub/user.xsl'));
    }

    /**
     * @dataProvider forbiddenReferenceProvider
     */
    public function testForbiddenReferences(string $reference, ?string $context = null): void
    {
        $this->assertNull($this->mappingPaths->resolve(strtr($reference, ['{root}' => $this->root]), $context));
    }

    public function forbiddenReferenceProvider(): array
    {
        return [
            'module parent' => ['module:../outside/secret.ini'],
            'user parent' => ['user:../outside/secret.ini'],
            'user deep parent' => ['user:sub/../../outside/secret.ini'],
            'absolute outside' => ['{root}/outside/secret.ini'],
            'absolute system' => ['/etc/passwd'],
            'relative parent' => ['../outside/secret.ini'],
            'context parent' => ['secret.ini', '../outside'],
            'symlink outside' => ['user:link.ini'],
            'directory' => ['module:ead'],
            'missing' => ['module:ead/missing.xsl'],
            'sibling prefix' => ['{root}/module_other/file.xsl'],
        ];
    }

    public function testIsModuleFile(): void
    {
        $this->assertTrue($this->mappingPaths->isModuleFile($this->root . '/module/root.xsl'));
        $this->assertFalse($this->mappingPaths->isModuleFile($this->root . '/user/sub/user.xsl'));
        $this->assertFalse($this->mappingPaths->isModuleFile($this->root . '/module/../outside/secret.ini'));
    }
}
