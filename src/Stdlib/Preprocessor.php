<?php declare(strict_types=1);

/**
 * Preprocessor - Applies preprocessing transformations before mapping.
 *
 * Supports xsl transformations via delegation to ProcessXslt.
 * The transformation type is determined by file extension.
 *
 * Sources can be:
 * - File paths (absolute or relative)
 * - Database references: "mapping:5" or "mapping:label"
 * - Prefixed references: "module:ead/transform.xsl" or "user:custom.xsl"
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

use Laminas\Log\Logger;
use Omeka\Api\Manager as ApiManager;

class Preprocessor
{
    use DatabaseMapperTrait;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ApiManager|null
     */
    protected $api;

    /**
     * @var ProcessXslt
     */
    protected $processXslt;

    /**
     * @var MappingPaths
     */
    protected $mappingPaths;

    /**
     * @var array Supported transformation types by extension.
     */
    protected const SUPPORTED_TYPES = [
        'xsl' => 'xsl',
        'xslt' => 'xsl',
    ];

    public function __construct(
        Logger $logger,
        ProcessXslt $processXslt,
        MappingPaths $mappingPaths,
        ?ApiManager $api = null
    ) {
        $this->logger = $logger;
        $this->processXslt = $processXslt;
        $this->mappingPaths = $mappingPaths;
        $this->api = $api;
    }

    /**
     * Apply preprocessing transformations to content.
     *
     * @param string $content The source content to transform.
     * @param array $preprocess List of transformation references to apply.
     * @param array $params Optional parameters to pass to transformations.
     * @param string|null $context Directory context for relative paths.
     * @return string|null The transformed content, or null on error.
     */
    public function __invoke(
        string $content,
        array $preprocess,
        array $params = [],
        ?string $context = null
    ): ?string {
        return $this->process($content, $preprocess, $params, $context);
    }

    /**
     * Apply preprocessing transformations to content.
     *
     * @param string $content The source content to transform.
     * @param array $preprocess List of transformation references to apply.
     *   References can be:
     *   - Simple filename: "transform.xsl" (searched in context/common/base)
     *   - Absolute path: "/path/to/transform.xsl"
     *   - Database: "mapping:5" or "mapping:My Transform"
     *   - Module file: "module:ead/transform.xsl"
     *   - User file: "user:custom.xsl"
     * @param array $params Optional parameters to pass to transformations.
     * @param string|null $context Directory context (e.g., "ead" for ead/).
     * @return string|null The transformed content, or null on error.
     */
    public function process(
        string $content,
        array $preprocess,
        array $params = [],
        ?string $context = null
    ): ?string {
        if (empty($preprocess)) {
            return $content;
        }

        foreach ($preprocess as $reference) {
            if (!is_string($reference) || !strlen($reference)) {
                continue;
            }

            // Load transformation data (from file or database).
            $transformData = $this->loadTransformation($reference, $context);
            if ($transformData === null) {
                $this->logger->err(
                    'Preprocessor: transformation not found: {reference}.',
                    ['reference' => $reference]
                );
                return null;
            }

            $type = $transformData['type'];
            if (!$type) {
                $this->logger->warn(
                    'Preprocessor: unsupported type for {reference}. Supported: {types}.',
                    ['reference' => $reference, 'types' => implode(', ', array_keys(self::SUPPORTED_TYPES))]
                );
                continue;
            }

            $result = $this->applyTransformation($content, $transformData, $type, $params);
            if ($result === null) {
                return null;
            }

            $content = $result;
        }

        return $content;
    }

    /**
     * Load transformation from reference.
     *
     * @param string $reference The transformation reference.
     * @param string|null $context Directory context for relative paths.
     * @return array|null Array with transformation data, or null if not found.
     */
    protected function loadTransformation(string $reference, ?string $context = null): ?array
    {
        // Database reference: "mapping:5" (by ID) or "mapping:label" (by label).
        if ($this->isDatabaseReference($reference)) {
            $mapper = $this->readDatabaseMapper($reference);
            return $mapper
                ? [
                    'content' => $mapper->mapping(),
                    'filepath' => null,
                    'type' => $this->getTypeFromExtension($mapper->label()),
                    'source' => 'database',
                    'reference' => $reference,
                ]
                : null;
        }

        // File reference: "module:ead/transform.xsl", "user:custom.xsl", or
        // a path, that cannot get out of the mapping directories.
        $filepath = $this->mappingPaths->resolve($reference, $context);
        if ($filepath) {
            $prefix = strtok($reference, ':');
            return [
                'content' => null,
                'filepath' => $filepath,
                'type' => $this->getTypeFromExtension($filepath),
                'source' => $prefix === 'module' || $prefix === 'user' ? $prefix : 'file',
                'reference' => $reference,
            ];
        }

        return null;
    }

    /**
     * Get transformation type from file extension.
     */
    protected function getTypeFromExtension(string $file): ?string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return self::SUPPORTED_TYPES[$extension] ?? null;
    }

    /**
     * Apply a single transformation.
     */
    protected function applyTransformation(
        string $content,
        array $transformData,
        string $type,
        array $params
    ): ?string {
        switch ($type) {
            case 'xsl':
                return $this->applyXsl($content, $transformData, $params);
            default:
                $this->logger->err(
                    'Preprocessor: unknown transformation type {type}.',
                    ['type' => $type]
                );
                return null;
        }
    }

    /**
     * Apply xsl transformation via ProcessXslt delegation.
     *
     * @param string $content Source xml content.
     * @param array $transformData Transformation data with 'filepath' or 'content'.
     * @param array $params Xsl parameters.
     * @return string|null Transformed content or null on error.
     */
    protected function applyXsl(
        string $content,
        array $transformData,
        array $params
    ): ?string {
        // Use filepath if available, otherwise use database content.
        $stylesheet = $transformData['filepath'] ?? $transformData['content'] ?? null;
        if ($stylesheet === null) {
            $this->logger->err(
                'Preprocessor: no stylesheet for {reference}.',
                ['reference' => $transformData['reference'] ?? 'unknown']
            );
            return null;
        }

        try {
            return $this->processXslt->processString($content, $stylesheet, $params);
        } catch (\Throwable $e) {
            $this->logger->err(
                'Preprocessor: xsl error for {reference}: {message}',
                [
                    'reference' => $transformData['reference'] ?? 'unknown',
                    'message' => $e->getMessage(),
                ]
            );
            return null;
        }
    }

    /**
     * Get the directory of the module mapping files (data/mapping).
     */
    public function getBasePath(): string
    {
        return $this->mappingPaths->modulePath();
    }

    /**
     * Get the directory of the user mapping files (files/mapping).
     */
    public function getUserBasePath(): string
    {
        return $this->mappingPaths->userPath();
    }

    /**
     * Check if xsl extension is available.
     */
    public function isXslAvailable(): bool
    {
        return $this->processXslt->isPhpXslAvailable();
    }

    /**
     * Check if external xslt processor is configured.
     */
    public function hasExternalProcessor(): bool
    {
        return $this->processXslt->hasExternalProcessor();
    }
}
