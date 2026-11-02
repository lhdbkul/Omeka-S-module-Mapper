<?php declare(strict_types=1);

namespace Mapper\Stdlib;

/**
 * Resolve the references of mapping files inside the allowed directories.
 *
 * The files are searched in the directory "data/mapping" of the module and in
 * the directory "files/mapping" of the user. A reference cannot get out of
 * them, for example with ".." or a symbolic link.
 */
class MappingPaths
{
    /**
     * @var string
     */
    protected $modulePath;

    /**
     * @var string
     */
    protected $userPath;

    public function __construct(string $modulePath, string $userPath)
    {
        $this->modulePath = rtrim($modulePath, '/');
        $this->userPath = rtrim($userPath, '/');
    }

    public function modulePath(): string
    {
        return $this->modulePath;
    }

    public function userPath(): string
    {
        return $this->userPath;
    }

    /**
     * Get the real path of a readable file from a reference.
     *
     * The reference may be "module:dir/file.xsl", "user:file.xsl", an absolute
     * path inside an allowed directory, or a path relative to the module
     * directory, searched in the context folder, then in "common", then at the
     * root.
     */
    public function resolve(string $reference, ?string $context = null): ?string
    {
        if (strncmp($reference, 'module:', 7) === 0) {
            return $this->fileInside($this->modulePath . '/' . substr($reference, 7), $this->modulePath);
        }

        if (strncmp($reference, 'user:', 5) === 0) {
            return $this->fileInside($this->userPath . '/' . substr($reference, 5), $this->userPath);
        }

        if (strpos($reference, '/') === 0) {
            return $this->fileInside($reference, $this->modulePath)
                ?? $this->fileInside($reference, $this->userPath);
        }

        $candidates = [];
        if ($context !== null && $context !== '') {
            $candidates[] = $context . '/' . $reference;
        }
        $candidates[] = 'common/' . $reference;
        $candidates[] = $reference;
        foreach ($candidates as $candidate) {
            $filepath = $this->fileInside($this->modulePath . '/' . $candidate, $this->modulePath);
            if ($filepath) {
                return $filepath;
            }
        }

        return null;
    }

    /**
     * Check if a file is inside the module directory, so it can be trusted, for
     * example to be included as php.
     */
    public function isModuleFile(string $filepath): bool
    {
        return $this->fileInside($filepath, $this->modulePath) !== null;
    }

    /**
     * Get the real path of a readable file when it is inside a directory.
     */
    protected function fileInside(string $filepath, string $directory): ?string
    {
        $realDirectory = realpath($directory);
        $realFilepath = realpath($filepath);
        if (!$realDirectory
            || !$realFilepath
            || strpos($realFilepath, $realDirectory . DIRECTORY_SEPARATOR) !== 0
            || !is_file($realFilepath)
            || !is_readable($realFilepath)
        ) {
            return null;
        }
        return $realFilepath;
    }
}
