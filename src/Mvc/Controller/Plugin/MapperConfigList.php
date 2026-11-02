<?php declare(strict_types=1);

namespace Mapper\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;

/**
 * Controller plugin for listing available mapping configurations.
 *
 * Lists mappings from:
 * - Database (configured mappings)
 * - User files (in files/mapping/)
 * - Module files (in module's data/mapping/)
 */
class MapperConfigList extends AbstractPlugin
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $mappingDirectory = '';

    /**
     * @var string
     */
    protected $mappingExtension = '';

    public function __construct(ApiManager $api, string $basePath)
    {
        $this->api = $api;
        $this->basePath = $basePath;
    }

    /**
     * Get this plugin instance.
     */
    public function __invoke(): self
    {
        return $this;
    }

    /**
     * List available mappings from all sources.
     *
     * @param array $subDirAndExtensions Array of subdirectory => extension pairs.
     *   Special value ['mapping' => true] lists database mappings.
     *   Special value ['*' => 'ext'] scans all subdirectories for that extension.
     *   Example: [['mapping' => true], ['xml' => 'ini'], ['*' => 'xsl']]
     * @return array Grouped list of mappings by source.
     */
    public function listMappings(array $subDirAndExtensions = []): array
    {
        $files = [
            'mapping' => [
                'label' => 'Configured mappings', // @translate
                'options' => [],
            ],
            'user' => [
                'label' => 'User mapping files', // @translate
                'options' => [],
            ],
            'module' => [
                'label' => 'Module mapping files', // @translate
                'options' => [],
            ],
        ];

        foreach ($subDirAndExtensions as $subDirAndExtension) {
            $extension = reset($subDirAndExtension);
            $subDirectory = key($subDirAndExtension);

            // Database mappings.
            if ($subDirectory === 'mapping' && $extension === true) {
                try {
                    $mappings = $this->api->search('mappers', [
                        'sort_by' => 'label',
                        'sort_order' => 'asc',
                    ])->getContent();
                    foreach ($mappings as $mapping) {
                        $files['mapping']['options']['mapping:' . $mapping->id()] = $mapping->label();
                    }
                } catch (\Throwable $e) {
                    // API not available or table doesn't exist.
                }
                continue;
            }

            // Scan all subdirectories for a given extension.
            if ($subDirectory === '*') {
                $this->mappingExtension = $extension;
                $subDirectories = $this->getSubDirectories(dirname(__DIR__, 4) . '/data/mapping');
                foreach ($subDirectories as $subDir) {
                    $this->mappingDirectory = dirname(__DIR__, 4) . '/data/mapping/' . $subDir;
                    $mappingFiles = $this->getMappingFiles();
                    foreach ($mappingFiles as $file) {
                        $files['module']['options']['module:' . $subDir . '/' . $file] = $subDir . '/' . $file;
                    }
                }
                $subDirectories = $this->getSubDirectories($this->basePath . '/mapping');
                foreach ($subDirectories as $subDir) {
                    $this->mappingDirectory = $this->basePath . '/mapping/' . $subDir;
                    $mappingFiles = $this->getMappingFiles();
                    foreach ($mappingFiles as $file) {
                        $files['user']['options']['user:' . $subDir . '/' . $file] = $subDir . '/' . $file;
                    }
                }
                continue;
            }

            $this->mappingExtension = $extension;

            // Module mapping files.
            $this->mappingDirectory = dirname(__DIR__, 4) . '/data/mapping/' . $subDirectory;
            $mappingFiles = $this->getMappingFiles();
            foreach ($mappingFiles as $file) {
                $files['module']['options']['module:' . $subDirectory . '/' . $file] = $file;
            }

            // User mapping files.
            $this->mappingDirectory = $this->basePath . '/mapping/' . $subDirectory;
            $mappingFiles = $this->getMappingFiles();
            foreach ($mappingFiles as $file) {
                $files['user']['options']['user:' . $subDirectory . '/' . $file] = $file;
            }
        }

        return $files;
    }

    /**
     * Get all subdirectories of a directory.
     *
     * @param string $directory
     * @return array List of subdirectory names.
     */
    protected function getSubDirectories(string $directory): array
    {
        $subDirs = [];
        if (!is_dir($directory)) {
            return $subDirs;
        }
        $iterator = new \DirectoryIterator($directory);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                $subDirs[] = $fileInfo->getFilename();
            }
        }
        sort($subDirs);
        return $subDirs;
    }

    /**
     * Get internal module mappings (built-in mapping files).
     *
     * @return array List of module mapping file references.
     */
    public function getInternalMappings(): array
    {
        static $internalMappings;

        if ($internalMappings === null) {
            // Search for all mapping files in all subdirectories.
            // Uses wildcard '*' to scan dynamically.
            $internalMappings = $this->listMappings([
                ['*' => 'ini'],
                ['*' => 'xml'],
                ['*' => 'json'],
                ['*' => 'xsl'],
            ])['module']['options'];
        }

        return $internalMappings;
    }

    /**
     * Load mapping content from a file reference.
     *
     * @param string $mappingName Reference like "user:xml/mapping.ini" or "module:json/mapping.xml".
     * @return string|null The file content or null if not found.
     */
    public function getMappingFromFile(string $mappingName): ?string
    {
        $filepath = &$mappingName;

        if (mb_substr($filepath, 0, 5) === 'user:') {
            $filepath = $this->basePath . '/mapping/' . mb_substr($filepath, 5);
        } elseif (mb_substr($filepath, 0, 7) === 'module:') {
            $filepath = dirname(__DIR__, 4) . '/data/mapping/' . mb_substr($filepath, 7);
        } else {
            return null;
        }

        $path = realpath($filepath) ?: null;
        if (!$path || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    /**
     * Get all mapping files from the current directory.
     *
     * @return array List of relative file paths.
     */
    protected function getMappingFiles(): array
    {
        $files = [];
        $dir = new \SplFileInfo($this->mappingDirectory);

        if (!$dir->isDir()) {
            return [];
        }

        $lengthDir = strlen($this->mappingDirectory) + 1;
        $dir = new \RecursiveDirectoryIterator($this->mappingDirectory);

        // Filter out inaccessible directories.
        $dir = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) {
            if ($iterator->isDir() && (!$iterator->isExecutable() || !$iterator->isReadable())) {
                return false;
            }
            return true;
        });

        $iterator = new \RecursiveIteratorIterator($dir);

        foreach ($iterator as $filepath => $file) {
            if ((!$this->mappingExtension || pathinfo($filepath, PATHINFO_EXTENSION) === $this->mappingExtension)
                && $this->checkMappingFile($file)
            ) {
                $relativePath = substr($filepath, $lengthDir);
                $files[$relativePath] = null;
            }
        }

        // Sort: directories first, then alphabetically.
        uksort($files, function ($a, $b) {
            if ($a === $b) {
                return 0;
            }
            $aInRoot = strpos($a, '/') === false;
            $bInRoot = strpos($b, '/') === false;
            if (($aInRoot && $bInRoot) || (!$aInRoot && !$bInRoot)) {
                return strcasecmp($a, $b);
            }
            return $bInRoot ? -1 : 1;
        });

        return array_combine(array_keys($files), array_keys($files));
    }

    /**
     * Validate a mapping file.
     *
     * @param \SplFileInfo $fileinfo
     * @return string|null Real path if valid, null otherwise.
     */
    protected function checkMappingFile(\SplFileInfo $fileinfo): ?string
    {
        $realPath = $fileinfo->getRealPath();
        $realDirectory = realpath((string) $this->mappingDirectory);
        if ($realPath === false || $realDirectory === false) {
            return null;
        }

        // Security: ensure file is within the mapping directory, that may be
        // a symlink.
        if (strpos($realPath, $realDirectory . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            return null;
        }

        return $realPath;
    }
}
