<?php declare(strict_types=1);

/**
 * MapperConfig - Parses and normalizes mapping configurations.
 *
 * Supports ini-style text, xml, and array formats for mapping definitions.
 *
 * ## Mapping Structure
 *
 * A mapping configuration has two levels of "sections":
 *
 * ### Level 1: Mapping Sections (top-level structure)
 *
 * ```
 * [info]      → Metadata: label, from, to, querier, mapper, preprocess, example
 * [params]    → Configuration parameters (key-value pairs)
 * [default]   → Default maps applied to all resources
 * [maps]      → Actual mapping rules (array of maps)
 * [tables]    → Lookup tables for value conversions
 * ```
 *
 * ### Level 2: Map Parts (structure of each map in 'default' or 'maps')
 *
 * ```
 * [from]  → Source: where data comes from (path, querier, index)
 * [to]    → Target: where data goes
 *            (field, property_id, datatype, language, is_public)
 * [mod]   → Modifiers: how to transform data
 *            (raw, val, pattern, prepend, append)
 * ```
 *
 * ### Example INI format:
 *
 * ```ini
 * [info]
 * label = "My Mapping"
 * querier = xpath
 *
 * [maps]
 * //title = dcterms:title ^^literal @fra
 * //creator = dcterms:creator ~ {{ value|trim }}
 * ```
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

use Common\Stdlib\EasyMeta;
use Laminas\Log\Logger;
use Omeka\Api\Manager as ApiManager;

class MapperConfig
{
    // =========================================================================
    // Mapping Section Constants (Level 1: top-level sections of a mapping)
    // =========================================================================

    /**
     * Metadata section: label, from, to, querier, mapper, preprocess, example.
     * Stores key-value pairs describing the mapping.
     * The 'preprocess' key is an array of transformation files (XSL, JQ, etc.)
     * to apply before mapping. The type is determined by file extension.
     */
    public const SECTION_INFO = 'info';

    /**
     * Parameters section: configuration values for the mapping.
     * Stores key-value pairs for custom settings.
     */
    public const SECTION_PARAMS = 'params';

    /**
     * Default maps section: maps applied when creating any resource.
     * Contains an array of map definitions (without source paths).
     *
     * @deprecated Use maps section instead. Default maps are now detected
     *             automatically by the absence of a source path (from.path).
     *             This section is kept for backward compatibility and will
     *             be merged into maps during normalization.
     */
    public const SECTION_DEFAULT = 'default';

    /**
     * Maps section: the actual mapping rules.
     * Contains an array of map definitions. Maps without a source path
     * (from.path) are treated as "default" maps and always applied.
     */
    public const SECTION_MAPS = 'maps';

    /**
     * Tables section: lookup tables for value conversions.
     * Contains nested associative arrays indexed by table name.
     */
    public const SECTION_TABLES = 'tables';

    /**
     * All valid mapping section names.
     */
    public const MAPPING_SECTIONS = [
        self::SECTION_INFO,
        self::SECTION_PARAMS,
        self::SECTION_DEFAULT,
        self::SECTION_MAPS,
        self::SECTION_TABLES,
    ];

    /**
     * Sections that contain arrays of maps.
     */
    public const MAP_SECTIONS = [
        self::SECTION_DEFAULT,
        self::SECTION_MAPS,
    ];

    /**
     * Sections that contain key-value pairs.
     */
    public const KEYVALUE_SECTIONS = [
        self::SECTION_INFO,
        self::SECTION_PARAMS,
    ];

    // =========================================================================
    // Variable Constants (for params evaluation)
    // =========================================================================

    /**
     * Static variables available at initialization.
     *
     * These variables are known when the mapping is first loaded and the
     * source URL/filename are provided. Params using only these variables
     * can be evaluated once at initialization.
     */
    public const STATIC_VARIABLES = [
        'url',
        'filename',
    ];

    /**
     * Dynamic variables available during resource processing.
     *
     * These variables change during mapping execution:
     * - page: current pagination page
     * - value: current extracted value
     * - url_resource: URL of the resource being processed
     * - {key}: PSR-3 style substitution from source data
     *
     * Params using these variables must be evaluated on each access.
     */
    public const DYNAMIC_VARIABLES = [
        'page',
        'value',
        'url_resource',
    ];

    // =========================================================================
    // Map Part Constants (Level 2: parts of each individual map)
    // =========================================================================

    /**
     * Source part: defines where data comes from.
     * Keys: path, querier, index.
     */
    public const MAP_FROM = 'from';

    /**
     * Target part: defines where data goes.
     * Keys: field, property_id, datatype, language, is_public.
     */
    public const MAP_TO = 'to';

    /**
     * Modifier part: defines how to transform data.
     *
     * Input keys: raw, pattern, prepend, append.
     * Computed keys (from PatternParser): replace, filters,
     * filters_has_replace.
     *
     * Note: 'val' is accepted as input but normalized to 'raw'.
     */
    public const MAP_MOD = 'mod';

    /**
     * All map parts.
     */
    public const MAP_PARTS = [
        self::MAP_FROM,
        self::MAP_TO,
        self::MAP_MOD,
    ];

    // =========================================================================
    // Dependencies
    // =========================================================================

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var EasyMeta
     */
    protected $easyMeta;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * Base path for user files.
     */
    protected string $basePath;

    /**
     * @var MapNormalizer
     */
    protected $mapNormalizer;

    /**
     * @var PatternParser
     */
    protected $patternParser;

    /**
     * Cache for parsed mappings.
     */
    protected array $mappings = [];

    /**
     * Current mapping name.
     */
    protected ?string $currentName = null;

    /**
     * Empty mapping template.
     *
     * Structure:
     * - info: Metadata about the mapping
     * - params: Configuration parameters
     * - default: Default maps (no source path)
     * - maps: Mapping rules (with source paths)
     * - tables: Lookup tables
     * - has_error: Error flag
     */
    protected const EMPTY_MAPPING = [
        self::SECTION_INFO => [
            'label' => null,
            'from' => null,
            'to' => null,
            'querier' => null,
            'mapper' => null,
            'preprocess' => [],
            'example' => null,
        ],
        self::SECTION_PARAMS => [],
        self::SECTION_DEFAULT => [],
        self::SECTION_MAPS => [],
        self::SECTION_TABLES => [],
        'has_error' => false,
    ];

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        Logger $logger,
        string $basePath,
        MapNormalizer $mapNormalizer,
        PatternParser $patternParser
    ) {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->basePath = $basePath;
        $this->mapNormalizer = $mapNormalizer;
        $this->patternParser = $patternParser;
    }

    /**
     * Load and parse a mapping configuration.
     *
     * @param string|null $name The name of the mapping.
     * @param array|string|null $mappingOrRef Full mapping or reference.
     * @param array $options Parsing options.
     * @return self|array Returns self for chaining, or the parsed mapping.
     */
    public function __invoke(?string $name = null, $mappingOrRef = null, array $options = [])
    {
        if ($name === null && $mappingOrRef === null) {
            return $this;
        }

        if ($name === null && $mappingOrRef !== null) {
            $name = $this->generateNameFromReference($mappingOrRef);
        }

        $this->currentName = $name;

        if ($mappingOrRef === null) {
            return $this->getMapping($name);
        }

        if (!isset($this->mappings[$name])) {
            $this->parseAndStore($mappingOrRef, $options);
        }

        return $this->getMapping($name);
    }

    /**
     * Get a parsed mapping by name.
     */
    public function getMapping(?string $name = null): ?array
    {
        return $this->mappings[$name ?? $this->currentName] ?? null;
    }

    /**
     * Check if a mapping has errors.
     */
    public function hasError(?string $name = null): bool
    {
        $mapping = $this->getMapping($name);
        return $mapping === null || !empty($mapping['has_error']);
    }

    /**
     * Get a section from the current mapping.
     */
    public function getSection(string $section): array
    {
        $mapping = $this->getMapping();
        return $mapping[$section] ?? [];
    }

    /**
     * Get a setting from a section.
     */
    public function getSectionSetting(string $section, string $name, $default = null)
    {
        $mapping = $this->getMapping();
        if (!$mapping || !isset($mapping[$section])) {
            return $default;
        }

        if (in_array($section, self::MAP_SECTIONS)) {
            foreach ($mapping[$section] as $map) {
                if ($name === ($map[self::MAP_FROM]['path'] ?? null)) {
                    return $map;
                }
            }
            return $default;
        }

        return $mapping[$section][$name] ?? $default;
    }

    /**
     * Get a sub-setting from a section.
     */
    public function getSectionSettingSub(string $section, string $name, string $subName, $default = null)
    {
        $mapping = $this->getMapping();
        return $mapping[$section][$name][$subName] ?? $default;
    }

    /**
     * Get the current mapping name.
     */
    public function getCurrentName(): ?string
    {
        return $this->currentName;
    }

    /**
     * Evaluate static params using provided variables.
     *
     * Static params are those whose patterns only reference static variables
     * (url, filename) or previously evaluated params. This method evaluates
     * these params once, replacing the pattern structure with the resulting
     * string value.
     *
     * @param array $variables Variables for evaluation ('url', 'filename').
     * @param string|null $name Mapping name (defaults to current).
     * @return self
     */
    public function evaluateStaticParams(array $variables, ?string $name = null): self
    {
        $name = $name ?? $this->currentName;
        if (!$name || !isset($this->mappings[$name])) {
            return $this;
        }

        $params = $this->mappings[$name][self::SECTION_PARAMS] ?? [];
        if (empty($params)) {
            return $this;
        }

        // Build context with static variables.
        $context = [];
        foreach (self::STATIC_VARIABLES as $varName) {
            if (isset($variables[$varName])) {
                $context[$varName] = $variables[$varName];
            }
        }

        // Evaluate params in order (order matters for dependencies).
        foreach ($params as $key => $value) {
            // Skip raw params (not patterns).
            if (!is_array($value) || !isset($value['pattern'])) {
                // Raw value - add to context for subsequent params.
                $context[$key] = $value;
                continue;
            }

            // Check if this param uses only static variables.
            if (!$this->isStaticParam($value)) {
                // Dynamic param - keep as-is but add empty to context.
                $context[$key] = '';
                continue;
            }

            // Evaluate the pattern with current context.
            $evaluated = $this->evaluatePattern($value, $context);
            if ($evaluated !== null) {
                // Replace the pattern structure with evaluated string.
                $this->mappings[$name][self::SECTION_PARAMS][$key] = $evaluated;
                // Add evaluated value to context for subsequent params.
                $context[$key] = $evaluated;
            }
        }

        return $this;
    }

    /**
     * Check if a param is static (uses only static variables).
     *
     * A param is static if its pattern only references:
     * - Static variables (url, filename)
     * - Previously defined params (which can be checked separately)
     * - Literal strings with filters
     *
     * @param array $paramValue The parsed param value with pattern/replace.
     * @return bool True if the param can be evaluated statically.
     */
    protected function isStaticParam(array $paramValue): bool
    {
        // Check for dynamic variables in pattern.
        $pattern = $paramValue['pattern'] ?? '';
        foreach (self::DYNAMIC_VARIABLES as $dynamicVar) {
            if (mb_strpos($pattern, '{{ ' . $dynamicVar) !== false) {
                return false;
            }
        }

        // Check for PSR-3 style substitutions {key} which are always dynamic.
        if (preg_match('/\{[^{}]+\}/', $pattern)) {
            // If there's a single-brace substitution that's not inside {{ }},
            // it's a dynamic data reference.
            $cleanPattern = preg_replace('/\{\{[^}]+\}\}/', '', $pattern);
            if (preg_match('/\{[^{}]+\}/', $cleanPattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a pattern param with given context.
     *
     * @param array $paramValue Parsed param value with pattern/replace.
     * @param array $context Variables available for replacement.
     * @return string|null Evaluated string or null if evaluation fails.
     */
    protected function evaluatePattern(array $paramValue, array $context): ?string
    {
        $pattern = $paramValue['pattern'] ?? '';
        if (!strlen($pattern)) {
            return null;
        }

        // Build replacements for {{ variable }} syntax.
        $replace = [];
        foreach ($context as $name => $value) {
            if (is_scalar($value)) {
                $replace['{{ ' . $name . ' }}'] = $value;
            }
        }

        // Apply simple replacements first.
        $result = strtr($pattern, $replace);

        // Apply filter expressions if present.
        if (!empty($paramValue['filters'])) {
            $result = $this->applySimpleFilters(
                $result,
                $context,
                $paramValue['filters']
            );
        }

        return $result;
    }

    /**
     * Apply simple filters for param evaluation.
     *
     * This is a simplified version of FilterTrait::applyFilters() that handles
     * common filters needed for param evaluation.
     *
     * @param string $result Current result string.
     * @param array $context Variables available.
     * @param array $filters Filter expressions to apply.
     * @return string Processed result.
     */
    protected function applySimpleFilters(string $result, array $context, array $filters): string
    {
        foreach ($filters as $expression) {
            // Extract the expression content: {{ variable|filter }}
            $inner = trim(mb_substr((string) $expression, 3, -3));
            $parts = array_filter(array_map('trim', explode('|', $inner)));

            if (empty($parts)) {
                continue;
            }

            // First part is the variable name.
            $varName = array_shift($parts);
            $value = $context[$varName] ?? '';

            // Apply each filter.
            foreach ($parts as $filter) {
                $value = $this->applySimpleFilter($value, $filter);
            }

            // Ensure final value is a string.
            if (is_array($value)) {
                $value = (string) reset($value);
            }

            // Replace the expression with the result.
            $result = strtr($result, [$expression => (string) $value]);
        }

        return $result;
    }

    /**
     * Apply a single filter for param evaluation.
     *
     * Only supports filters commonly used in params.
     *
     * @param mixed $value The value to filter.
     * @param string $filter The filter with optional arguments.
     * @return string|array Filtered value (array for split, string else).
     */
    protected function applySimpleFilter($value, string $filter)
    {
        $stringValue = is_array($value) ? (string) reset($value) : (string) $value;

        // Parse filter name and arguments.
        if (preg_match('~\s*(?<function>[a-zA-Z0-9_]+)\s*\(\s*(?<args>.*?)\s*\)\s*~U', $filter, $matches)) {
            $function = $matches['function'];
            $args = $matches['args'];
        } else {
            $function = $filter;
            $args = '';
        }

        switch ($function) {
            case 'basename':
                return basename($stringValue);

            case 'first':
                if (is_array($value)) {
                    return (string) reset($value);
                }
                return mb_substr($stringValue, 0, 1);

            case 'last':
                if (is_array($value)) {
                    return (string) end($value);
                }
                return mb_substr($stringValue, -1);

            case 'lower':
                return mb_strtolower($stringValue);

            case 'upper':
                return mb_strtoupper($stringValue);

            case 'trim':
                return trim($stringValue);

            case 'split':
                // Extract arguments.
                $argList = $this->extractSimpleList($args);
                $delimiter = $argList[0] ?? '';
                if (!strlen($delimiter)) {
                    return $stringValue;
                }
                $limit = isset($argList[1]) ? (int) $argList[1] : PHP_INT_MAX;
                // Return as array for further processing.
                return explode($delimiter, $stringValue, $limit);

            case 'slice':
                $argList = $this->extractSimpleList($args);
                $start = (int) ($argList[0] ?? 0);
                $length = isset($argList[1]) ? (int) $argList[1] : 1;
                if (is_array($value)) {
                    $sliced = array_slice($value, $start, $length);
                    return (string) reset($sliced);
                }
                return mb_substr($stringValue, $start, $length);

            default:
                return $stringValue;
        }
    }

    /**
     * Extract a simple list of arguments from a string.
     *
     * @param string $args Argument string like "'/', -1" or "1, 4".
     * @return array List of argument values.
     */
    protected function extractSimpleList(string $args): array
    {
        $result = [];
        $matches = [];
        preg_match_all('~"([^"]*)"|\'([^\']*)\'|([+-]?\d+)~', $args, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (isset($match[3]) && $match[3] !== '') {
                $result[] = $match[3];
            } elseif (isset($match[2]) && $match[2] !== '') {
                $result[] = $match[2];
            } elseif (isset($match[1])) {
                $result[] = $match[1];
            }
        }
        return $result;
    }

    /**
     * Verify param order in a mapping.
     *
     * Checks that params referencing other params are defined after them.
     * Logs warnings for out-of-order params.
     *
     * @param array $params The params section to verify.
     * @return array List of warnings (empty if valid).
     */
    public function verifyParamOrder(array $params): array
    {
        $warnings = [];
        $defined = [];

        foreach ($params as $key => $value) {
            // Check if this param references other params.
            if (is_array($value) && isset($value['pattern'])) {
                $pattern = $value['pattern'];

                // Find all {{ variable }} references.
                preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:\|[^}]*)?\}\}/', $pattern, $matches);
                foreach ($matches[1] as $refVar) {
                    // Skip static and dynamic variables.
                    if (in_array($refVar, self::STATIC_VARIABLES) || in_array($refVar, self::DYNAMIC_VARIABLES)) {
                        continue;
                    }
                    // Check if referenced param is defined before this one.
                    if (!in_array($refVar, $defined) && isset($params[$refVar])) {
                        $warnings[] = sprintf(
                            'Param "%s" references "%s" which is defined later. Move "%s" before "%s".',
                            $key,
                            $refVar,
                            $refVar,
                            $key
                        );
                    }
                }
            }

            $defined[] = $key;
        }

        return $warnings;
    }

    /**
     * Normalize a list of maps.
     *
     * Delegates to MapNormalizer when available.
     */
    public function normalizeMaps(array $maps, array $options = []): array
    {
        if (empty($maps)) {
            return [];
        }

        $result = [];
        foreach ($maps as $index => $map) {
            $options['index'] = $index;

            if (empty($map)) {
                $result[] = [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => []];
                continue;
            }

            $normalized = $this->normalizeMap($map, $options);

            // When the source index (column header) contains qualifiers
            // like ^^datatype, @language, or §visibility, transfer them
            // to the target if not already set. This handles the case
            // where the form sends only the property term as value but
            // the column header has the full spec.
            if (is_string($index) && preg_match('/[\^@§]/', $index)) {
                $sourceSpec = $this->mapNormalizer->parseFieldSpec($index);
                $normalized = $this->applySourceQualifiers($normalized, $sourceSpec);
            }

            if (!empty($normalized) && is_numeric(key($normalized))) {
                foreach ($normalized as $item) {
                    $result[] = $item;
                }
            } else {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * Apply qualifiers from the source spec to the target if missing.
     */
    protected function applySourceQualifiers(array $normalized, array $sourceSpec): array
    {
        if (!empty($normalized) && is_numeric(key($normalized))) {
            foreach ($normalized as &$item) {
                $item = $this->applySourceQualifiersToMap($item, $sourceSpec);
            }
            unset($item);
        } else {
            $normalized = $this->applySourceQualifiersToMap($normalized, $sourceSpec);
        }
        return $normalized;
    }

    protected function applySourceQualifiersToMap(array $map, array $sourceSpec): array
    {
        if (empty($map[self::MAP_TO])) {
            return $map;
        }
        if (!empty($sourceSpec['datatype']) && empty($map[self::MAP_TO]['datatype'])) {
            $map[self::MAP_TO]['datatype'] = $sourceSpec['datatype'];
        }
        if ($sourceSpec['language'] !== null && ($map[self::MAP_TO]['language'] ?? null) === null) {
            $map[self::MAP_TO]['language'] = $sourceSpec['language'];
        }
        if ($sourceSpec['is_public'] !== null && ($map[self::MAP_TO]['is_public'] ?? null) === null) {
            $map[self::MAP_TO]['is_public'] = $sourceSpec['is_public'];
        }
        return $map;
    }

    /**
     * Normalize a single map from various input formats.
     *
     * Uses MapNormalizer for the heavy lifting, then converts to legacy format.
     */
    public function normalizeMap($map, array $options = []): array
    {
        if (empty($map)) {
            return [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => []];
        }

        if (is_string($map)) {
            return $this->normalizeMapFromString($map, $options);
        }

        if (is_array($map)) {
            if (is_numeric(key($map))) {
                return array_map(fn($m) => $this->normalizeMap($m, $options), $map);
            }
            return $this->normalizeMapFromArray($map, $options);
        }

        return [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => [], 'has_error' => true];
    }

    /**
     * Parse and store a mapping.
     */
    protected function parseAndStore($mappingOrRef, array $options): void
    {
        $mapping = null;

        if (empty($mappingOrRef)) {
            $mapping = self::EMPTY_MAPPING;
        } elseif (is_array($mappingOrRef)) {
            $mapping = isset($mappingOrRef['info'])
                ? $this->parseNormalizedArray($mappingOrRef, $options)
                : $this->parseMapList($mappingOrRef, $options);
        } else {
            $content = $this->loadMappingContent((string) $mappingOrRef);
            if ($content) {
                // PHP files return an array directly.
                if (is_array($content)) {
                    $mapping = isset($content['info'])
                        ? $this->parseNormalizedArray($content, $options)
                        : $this->parseMapList($content, $options);
                } else {
                    $mapping = $this->parseContent($content, $options);
                }
            }
        }

        if (!$mapping) {
            $mapping = self::EMPTY_MAPPING;
            $mapping['has_error'] = true;
            $this->logger->err('Mapping "{name}" could not be loaded.', ['name' => $this->currentName]);
        }

        $this->mappings[$this->currentName] = $mapping;
    }

    /**
     * Generate a name from a mapping reference.
     */
    protected function generateNameFromReference($mappingOrRef): string
    {
        if (is_string($mappingOrRef)) {
            return $mappingOrRef;
        }
        return md5(serialize($mappingOrRef));
    }

    /**
     * Load mapping content from a reference.
     *
     * @return string|array|null String, array (PHP), or null on error.
     */
    protected function loadMappingContent(string $reference)
    {
        // Database reference: "mapping:5" (by ID) or "mapping:My Mapping" (by label).
        if (mb_substr($reference, 0, 8) === 'mapping:') {
            $identifier = mb_substr($reference, 8);
            try {
                // Numeric = ID, otherwise = label.
                if (ctype_digit($identifier)) {
                    $mapper = $this->api->read('mappers', ['id' => (int) $identifier])->getContent();
                } else {
                    // Search by label.
                    $mappers = $this->api->search('mappers', ['label' => $identifier, 'limit' => 1])->getContent();
                    $mapper = $mappers[0] ?? null;
                    if (!$mapper) {
                        return null;
                    }
                }
                return $mapper->mapping();
            } catch (\Exception $e) {
                return null;
            }
        }

        // File reference with prefix.
        $prefixes = [
            'user' => $this->basePath . '/mapping/',
            'module' => dirname(__DIR__, 2) . '/data/mapping/',
        ];

        $isFileReference = false;
        if (strpos($reference, ':') !== false) {
            $prefix = strtok($reference, ':');
            if (isset($prefixes[$prefix])) {
                $isFileReference = true;
                $file = mb_substr($reference, strlen($prefix) + 1);
                $filepath = $prefixes[$prefix] . $file;
                if (file_exists($filepath) && is_readable($filepath)) {
                    return $this->loadFileContent($filepath);
                }
                return null;
            }
        }

        // Check for raw content (INI or XML).
        $trimmed = trim($reference);
        if (strlen($trimmed) > 10 && (
            mb_substr($trimmed, 0, 1) === '<' ||
            mb_substr($trimmed, 0, 1) === '[' ||
            strpos($trimmed, ' = ') !== false ||
            strpos($trimmed, "\n") !== false
        )) {
            return $trimmed;
        }

        // Try as module file without prefix.
        $filepath = $prefixes['module'] . $reference;
        if (file_exists($filepath) && is_readable($filepath)) {
            return $this->loadFileContent($filepath);
        }

        return null;
    }

    /**
     * Load file content, handling PHP files specially.
     *
     * @return string|array|null String for text files, array for PHP files.
     */
    protected function loadFileContent(string $filepath)
    {
        // PHP files are included and must return an array.
        if (pathinfo($filepath, PATHINFO_EXTENSION) === 'php') {
            $data = include $filepath;
            if (!is_array($data)) {
                $this->logger->err(
                    'PHP mapping file "{file}" must return an array.',
                    ['file' => $filepath]
                );
                return null;
            }
            return $data;
        }

        return trim((string) file_get_contents($filepath));
    }

    /**
     * Parse content string (ini, xml, or json).
     */
    protected function parseContent(string $content, array $options): array
    {
        $content = trim($content);
        if (!strlen($content)) {
            return self::EMPTY_MAPPING;
        }

        $firstChar = mb_substr($content, 0, 1);

        // XML format: starts with "<".
        if ($firstChar === '<') {
            $content = $this->processXmlIncludes($content, $options);
            $mapping = $this->parseXml($content, $options);
        }
        // JSON object: starts with "{".
        elseif ($firstChar === '{') {
            $mapping = $this->parseJson($content, $options);
        }
        // Could be JSON array "[...]" or INI section "[section]".
        // JSON arrays start with "[" then "{", "[", "]", or value.
        // INI sections start with "[" followed by a word character.
        elseif ($firstChar === '[') {
            // Check second non-whitespace character to distinguish.
            $afterBracket = ltrim(mb_substr($content, 1));
            $secondChar = mb_substr($afterBracket, 0, 1);
            // JSON array: next char is {, [, ], ", number, true/false/null.
            if (in_array($secondChar, ['{', '[', ']', '"'], true)
                || is_numeric($secondChar)
                || preg_match('/^(true|false|null)/i', $afterBracket)
            ) {
                $mapping = $this->parseJson($content, $options);
            } else {
                // INI section: [section_name].
                $mapping = $this->parseIni($content, $options);
            }
        }
        // INI format: everything else.
        else {
            $mapping = $this->parseIni($content, $options);
        }

        return $this->finalizeMapping($mapping);
    }

    /**
     * Parse JSON content into mapping structure.
     */
    protected function parseJson(string $content, array $options): array
    {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->err(
                'Invalid JSON in mapping "{name}": {error}',
                ['name' => $this->currentName, 'error' => json_last_error_msg()]
            );
            return self::EMPTY_MAPPING;
        }

        // JSON can be a full mapping structure or just a list of maps.
        if (isset($data['info']) || isset($data['maps']) || isset($data['params'])) {
            return $this->parseNormalizedArray($data, $options);
        }

        // Assume it's a list of maps (array format from CopIdRef/BulkImport).
        return $this->parseMapList($data, $options);
    }

    /**
     * Process XML include directives.
     *
     * Replaces `<include mapping="file.xml"/>` with the referenced content.
     *
     * @param string $content The XML content to process.
     * @param array $options Parsing options.
     * @param int $depth Current recursion depth.
     * @param string|null $baseDir Base directory for relative includes.
     * @return string The processed XML content.
     */
    protected function processXmlIncludes(string $content, array $options, int $depth = 0, ?string $baseDir = null): string
    {
        // Prevent infinite recursion (max 10 levels).
        if ($depth > 10) {
            $this->logger->warn('XML include depth limit exceeded in mapping "{name}".', ['name' => $this->currentName]);
            return $content;
        }

        // Determine base directory from current name if not provided.
        if ($baseDir === null && $this->currentName) {
            $baseDir = dirname($this->currentName);
            if ($baseDir === '.') {
                $baseDir = null;
            }
        }

        // Find all <include mapping="..."/> elements.
        $pattern = '/<include\s+mapping\s*=\s*["\']([^"\']+)["\']\s*\/>/i';
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $content;
        }

        foreach ($matches as $match) {
            $includeTag = $match[0];
            $includePath = $match[1];

            // Resolve relative path if baseDir is set.
            $resolvedPath = $includePath;
            if ($baseDir && strpos($includePath, ':') === false && strpos($includePath, '/') !== 0) {
                $resolvedPath = $baseDir . '/' . $includePath;
            }

            // Load the included file content.
            $includedContent = $this->loadMappingContent($resolvedPath);
            if ($includedContent === null) {
                $this->logger->warn(
                    'Could not load included mapping "{file}" in "{name}".',
                    ['file' => $resolvedPath, 'name' => $this->currentName]
                );
                // Remove the include tag.
                $content = strtr($content, [$includeTag => '']);
                continue;
            }

            // Recursively process includes in the included file.
            // Use the resolved path's directory as base for nested includes.
            $nestedBaseDir = dirname($resolvedPath);
            if ($nestedBaseDir === '.') {
                $nestedBaseDir = $baseDir;
            }
            $includedContent = $this->processXmlIncludes($includedContent, $options, $depth + 1, $nestedBaseDir);

            // Extract inner content (strip <mapping> wrapper).
            $innerContent = $this->extractXmlInnerContent($includedContent);

            // Replace the include tag with the inner content.
            $content = strtr($content, [$includeTag => $innerContent]);
        }

        return $content;
    }

    /**
     * Extract inner content from an XML mapping file.
     *
     * @param string $content The full XML content.
     * @return string The inner content (between <mapping> and </mapping>).
     */
    protected function extractXmlInnerContent(string $content): string
    {
        // Remove XML declaration if present.
        $content = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $content);

        // Extract content between <mapping> and </mapping>.
        if (preg_match('/<mapping[^>]*>(.*)<\/mapping>/is', $content, $match)) {
            return trim($match[1]);
        }

        // If no <mapping> wrapper, return as-is.
        return trim($content);
    }

    /**
     * Finalize a mapping by merging inherited mappings and deprecated sections.
     *
     * This method:
     * - Loads and merges base mapping if info.mapper is set (INI inheritance)
     * - Merges 'default' section into 'maps' (default is deprecated)
     * - Verifies param order for dependent params
     * - Logs deprecation warnings as appropriate
     *
     * @param array $mapping The parsed mapping.
     * @param array $inheritanceChain Mapping names in chain (recursion check).
     */
    protected function finalizeMapping(array $mapping, array $inheritanceChain = []): array
    {
        // Handle mapper inheritance (INI-style).
        $mapping = $this->processMapperInheritance($mapping, $inheritanceChain);

        // Merge 'default' section into 'maps'.
        if (!empty($mapping[self::SECTION_DEFAULT])) {
            // Log deprecation warning for user awareness.
            $this->logger->notice(
                'Mapping "{name}": The [default] section is deprecated. Move its content to [maps] section. Maps without a source path are automatically treated as default maps.', // @translate
                ['name' => $this->currentName ?? 'unknown']
            );

            // Prepend default maps (they should be processed first).
            $mapping[self::SECTION_MAPS] = array_merge(
                $mapping[self::SECTION_DEFAULT],
                $mapping[self::SECTION_MAPS] ?? []
            );
            // Clear the default section (keep empty array for structure).
            $mapping[self::SECTION_DEFAULT] = [];
        }

        // Verify param order and log warnings.
        if (!empty($mapping[self::SECTION_PARAMS])) {
            $warnings = $this->verifyParamOrder($mapping[self::SECTION_PARAMS]);
            foreach ($warnings as $warning) {
                $this->logger->warn(
                    'Mapping "{name}": {warning}', // @translate
                    ['name' => $this->currentName ?? 'unknown', 'warning' => $warning]
                );
            }
        }

        return $mapping;
    }

    /**
     * Process mapper inheritance via the info.mapper key.
     *
     * When a mapping has info.mapper set, the referenced base mapping is loaded
     * and merged with the current mapping. The current mapping's values take
     * precedence over the base mapping's values.
     *
     * @param array $mapping The current mapping.
     * @param array $inheritanceChain Names of mappings in the current chain.
     * @return array The merged mapping.
     */
    protected function processMapperInheritance(array $mapping, array $inheritanceChain): array
    {
        $baseMapperRef = $mapping[self::SECTION_INFO]['mapper'] ?? null;
        if (empty($baseMapperRef) || !is_string($baseMapperRef)) {
            return $mapping;
        }

        // Normalize the reference.
        // Database or prefixed references (mapper:5, module:path) kept as-is.
        if (strpos($baseMapperRef, ':') === false) {
            // Add default folder prefix if not present.
            // This check is only used for backward compatibility.
            if (strpos($baseMapperRef, '/') === false) {
                $baseMapperRef = 'base/' . $baseMapperRef;
            }
            // Add extension if not present.
            if (!str_ends_with($baseMapperRef, '.ini') && !str_ends_with($baseMapperRef, '.xml')) {
                $baseMapperRef .= '.ini';
            }
        }

        // Skip if the base mapper is the same as the current file.
        if ($baseMapperRef === $this->currentName) {
            return $mapping;
        }

        // Check for circular inheritance.
        if (in_array($baseMapperRef, $inheritanceChain)) {
            $this->logger->warn(
                'Circular inheritance detected in mapping "{name}": {chain}',
                ['name' => $this->currentName, 'chain' => implode(' -> ', $inheritanceChain) . ' -> ' . $baseMapperRef]
            );
            return $mapping;
        }

        // Limit inheritance depth.
        if (count($inheritanceChain) >= 10) {
            $this->logger->warn(
                'Mapper inheritance depth limit exceeded in mapping "{name}".',
                ['name' => $this->currentName]
            );
            return $mapping;
        }

        // Load the base mapping content.
        $baseContent = $this->loadMappingContent($baseMapperRef);
        if ($baseContent === null) {
            $this->logger->warn(
                'Could not load base mapping "{base}" for "{name}".',
                ['base' => $baseMapperRef, 'name' => $this->currentName]
            );
            return $mapping;
        }

        // Parse the base mapping (without storing it under a different name).
        $baseContent = trim($baseContent);
        if (!strlen($baseContent)) {
            return $mapping;
        }

        // Process includes if XML.
        if (mb_substr($baseContent, 0, 1) === '<') {
            $baseContent = $this->processXmlIncludes($baseContent, [], 0, dirname($baseMapperRef));
            $baseMapping = $this->parseXml($baseContent, []);
        } else {
            $baseMapping = $this->parseIni($baseContent, []);
        }

        // Recursively finalize the base mapping (handles nested inheritance).
        $inheritanceChain[] = $baseMapperRef;
        $baseMapping = $this->finalizeMapping($baseMapping, $inheritanceChain);

        // Merge: base first, then current on top.
        return $this->mergeMappings($baseMapping, $mapping);
    }

    /**
     * Merge two mappings (base and current).
     *
     * Current mapping values take precedence over base mapping values.
     * Maps are concatenated (base maps first, then current maps).
     *
     * @param array $base The base mapping.
     * @param array $current The current mapping.
     * @return array The merged mapping.
     */
    protected function mergeMappings(array $base, array $current): array
    {
        $merged = self::EMPTY_MAPPING;

        // Info: current overrides base (except for null values).
        $merged[self::SECTION_INFO] = array_filter($current[self::SECTION_INFO] ?? [], fn($v) => $v !== null)
            + array_filter($base[self::SECTION_INFO] ?? [], fn($v) => $v !== null)
            + self::EMPTY_MAPPING[self::SECTION_INFO];

        // Params: current overrides base.
        $merged[self::SECTION_PARAMS] = array_merge(
            $base[self::SECTION_PARAMS] ?? [],
            $current[self::SECTION_PARAMS] ?? []
        );

        // Tables: deep merge, current overrides base.
        $merged[self::SECTION_TABLES] = array_replace_recursive(
            $base[self::SECTION_TABLES] ?? [],
            $current[self::SECTION_TABLES] ?? []
        );

        // Maps: concatenate (base first, then current).
        $merged[self::SECTION_MAPS] = array_merge(
            $base[self::SECTION_MAPS] ?? [],
            $current[self::SECTION_MAPS] ?? []
        );

        // Default: concatenate (will be merged into maps later).
        $merged[self::SECTION_DEFAULT] = array_merge(
            $base[self::SECTION_DEFAULT] ?? [],
            $current[self::SECTION_DEFAULT] ?? []
        );

        // Preserve error flag from current.
        $merged['has_error'] = $current['has_error'] ?? $base['has_error'] ?? false;

        return $merged;
    }

    /**
     * Parse ini-style mapping content.
     */
    protected function parseIni(string $content, array $options): array
    {
        $mapping = self::EMPTY_MAPPING;
        $mapping[self::SECTION_INFO]['label'] = $this->currentName;

        $lines = array_filter(array_map('trim', explode("\n", $content)));

        // Default section is 'maps' when no section header is specified.
        $section = self::SECTION_MAPS;

        // Set default querier for MapNormalizer.
        $defaultQuerier = null;

        foreach ($lines as $line) {
            if (mb_substr($line, 0, 1) === ';') {
                continue;
            }

            if (mb_substr($line, 0, 1) === '[' && mb_substr($line, -1) === ']') {
                $section = trim(mb_substr($line, 1, -1));
                if (!in_array($section, self::MAPPING_SECTIONS)) {
                    $section = null;
                }
                continue;
            }

            if (!$section) {
                continue;
            }

            $map = $this->parseIniLine($line, $section, $options);
            if ($map === null) {
                continue;
            }

            if (in_array($section, self::KEYVALUE_SECTIONS)) {
                if (isset($map[self::MAP_FROM]) && is_scalar($map[self::MAP_FROM])) {
                    $key = $map[self::MAP_FROM];
                    // Handle array notation: key[] = value
                    if (mb_substr($key, -2) === '[]') {
                        $key = mb_substr($key, 0, -2);
                        $mapping[$section][$key][] = $map[self::MAP_TO];
                    } else {
                        $mapping[$section][$key] = $map[self::MAP_TO];
                    }
                    // Capture querier for later use.
                    if ($section === self::SECTION_INFO && $key === 'querier') {
                        $defaultQuerier = $map[self::MAP_TO];
                    }
                }
            } elseif ($section === self::SECTION_TABLES) {
                if (isset($map[self::MAP_FROM]) && isset($map[self::MAP_TO])) {
                    // Tables format: table.key = value
                    // MAP_FROM may be string or array.
                    $tablePath = is_array($map[self::MAP_FROM])
                        ? ($map[self::MAP_FROM]['path'] ?? '')
                        : $map[self::MAP_FROM];
                    if (is_string($tablePath)) {
                        $dotPos = mb_strpos($tablePath, '.');
                        if ($dotPos !== false) {
                            $tableName = mb_substr($tablePath, 0, $dotPos);
                            $tableKey = mb_substr($tablePath, $dotPos + 1);
                            $tableValue = is_array($map[self::MAP_TO])
                                ? ($map[self::MAP_TO]['field'] ?? '')
                                : $map[self::MAP_TO];
                            $mapping[self::SECTION_TABLES][$tableName][$tableKey] = $tableValue;
                        }
                    }
                }
            } else {
                $mapping[$section][] = $map;
            }
        }

        return $mapping;
    }

    /**
     * Parse a single ini line.
     */
    protected function parseIniLine(string $line, string $section, array $options): ?array
    {
        // Find the equals sign that separates source from destination.
        $equalsPos = $this->findSeparatorEquals($line);

        if ($equalsPos === false) {
            return null;
        }

        $from = trim(mb_substr($line, 0, $equalsPos));
        $to = trim(mb_substr($line, $equalsPos + 1));

        if (!strlen($from) && !strlen($to)) {
            return null;
        }

        if (in_array($section, self::KEYVALUE_SECTIONS)) {
            // Strip quotes if present.
            if ((mb_substr($to, 0, 1) === '"' && mb_substr($to, -1) === '"')
                || (mb_substr($to, 0, 1) === "'" && mb_substr($to, -1) === "'")
            ) {
                $to = mb_substr($to, 1, -1);
            }

            // For params section, check if value is a pattern (starts with ~).
            if ($section === self::SECTION_PARAMS && mb_substr(ltrim($to), 0, 1) === '~') {
                $patternPart = trim(mb_substr(ltrim($to), 1));
                $to = $this->parsePattern($patternPart);
            }

            return [self::MAP_FROM => $from, self::MAP_TO => $to];
        }

        $options['section'] = $section;
        return $this->normalizeMapFromIniParts($from, $to, $options);
    }

    /**
     * Find the equals sign separating source from destination in an INI line.
     *
     * This method correctly handles XPath predicates with "=" inside brackets,
     * e.g., //datafield[@tag="200"]/subfield[@code="a"] = dcterms:title
     *
     * @return int|false Position of separator "=" or false if not found.
     *
     * @todo Clarify the convention to separate left from right.
     */
    protected function findSeparatorEquals(string $line)
    {
        $length = mb_strlen($line);
        $bracketDepth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $lastEqualsOutsideBrackets = false;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($line, $i, 1);

            // Track quote state (to handle quoted strings with brackets).
            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }
            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }

            // Skip characters inside quotes.
            if ($inSingleQuote || $inDoubleQuote) {
                continue;
            }

            // Track bracket depth for XPath predicates.
            if ($char === '[') {
                $bracketDepth++;
                continue;
            }
            if ($char === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
                continue;
            }

            // Found "=" outside brackets - this is a potential separator.
            if ($char === '=' && $bracketDepth === 0) {
                $lastEqualsOutsideBrackets = $i;
            }
        }

        return $lastEqualsOutsideBrackets;
    }

    /**
     * Normalize a map from ini from/to parts.
     */
    protected function normalizeMapFromIniParts(string $from, string $to, array $options): array
    {
        $map = [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => []];

        // Check if "to" is a raw value (quoted).
        $isRaw = (mb_substr($to, 0, 1) === '"' && mb_substr($to, -1) === '"')
            || (mb_substr($to, 0, 1) === "'" && mb_substr($to, -1) === "'");

        if ($isRaw) {
            $map[self::MAP_MOD]['raw'] = mb_substr($to, 1, -1);
            $map[self::MAP_TO] = $this->parseFieldSpec($from);
            return $map;
        }

        // Set source path if not empty or tilde.
        if ($from !== '~' && $from !== '') {
            $map[self::MAP_FROM]['path'] = $from;
        }

        // Parse destination with optional pattern.
        $tildePos = mb_strpos($to, '~');
        if ($tildePos !== false) {
            $fieldPart = trim(mb_substr($to, 0, $tildePos));
            $patternPart = trim(mb_substr($to, $tildePos + 1));

            $map[self::MAP_TO] = $this->parseFieldSpec($fieldPart);
            $map[self::MAP_MOD] = $this->parsePattern($patternPart);
        } else {
            $map[self::MAP_TO] = $this->parseFieldSpec($to);
        }

        return $map;
    }

    /**
     * Parse a field specification string.
     *
     * Format: "dcterms:title ^^datatype @language §visibility"
     */
    protected function parseFieldSpec(string $spec): array
    {
        $result = [
            'field' => null,
            'datatype' => [],
            'language' => null,
            'is_public' => null,
        ];

        $spec = trim($spec);
        if (!$spec) {
            return $result;
        }

        $tildePos = mb_strpos($spec, '~');
        if ($tildePos !== false) {
            $spec = trim(mb_substr($spec, 0, $tildePos));
        }

        $parts = preg_split('/\s+/', $spec);
        foreach ($parts as $part) {
            if (mb_substr($part, 0, 2) === '^^') {
                $result['datatype'][] = mb_substr($part, 2);
            } elseif (mb_substr($part, 0, 1) === '@') {
                $result['language'] = mb_substr($part, 1);
            } elseif (mb_substr($part, 0, 1) === '§') {
                $visibility = mb_strtolower(mb_substr($part, 1));
                $result['is_public'] = $visibility !== 'private';
            } elseif ($result['field'] === null) {
                $result['field'] = $part;
            }
        }

        if ($result['field']) {
            $propertyId = $this->easyMeta->propertyId($result['field']);
            if ($propertyId) {
                $result['property_id'] = $propertyId;
            }
        }

        return $result;
    }

    /**
     * Parse a pattern string for replacements and filter expressions.
     */
    protected function parsePattern(string $pattern): array
    {
        $parsed = $this->patternParser->parse($pattern);
        return [
            'pattern' => $parsed['pattern'],
            'replace' => $parsed['replace'],
            'filters' => $parsed['filters'],
            'filters_has_replace' => $parsed['filters_has_replace'],
        ];
    }

    /**
     * Parse xml mapping content.
     */
    protected function parseXml(string $content, array $options): array
    {
        $mapping = self::EMPTY_MAPPING;
        $mapping[self::SECTION_INFO]['label'] = $this->currentName;

        try {
            $xml = new \SimpleXMLElement($content);
        } catch (\Exception $e) {
            $mapping['has_error'] = true;
            $this->logger->err('Invalid xml mapping: ' . $e->getMessage());
            return $mapping;
        }

        if (isset($xml->info)) {
            foreach ($xml->info->children() as $element) {
                $name = $element->getName();
                // Handle repeatable elements as arrays.
                if ($name === 'preprocess') {
                    $mapping['info']['preprocess'][] = (string) $element;
                } else {
                    $mapping['info'][$name] = (string) $element;
                }
            }
        }

        // Handle <params> container (recommended) or direct <param> elements.
        if (isset($xml->params)) {
            foreach ($xml->params->children() as $element) {
                $name = (string) ($element['name'] ?? $element->getName());
                $mapping['params'][$name] = (string) $element;
            }
        }
        foreach ($xml->param as $element) {
            $name = (string) ($element['name'] ?? '');
            if (strlen($name)) {
                $mapping['params'][$name] = (string) $element;
            }
        }

        // Handle <maps> container (recommended) and/or direct <map> elements.
        // Both are cumulative (like <param>).
        if (isset($xml->maps)) {
            foreach ($xml->maps->map as $mapElement) {
                $mapping[self::SECTION_MAPS][] = $this->parseXmlMap($mapElement);
            }
        }
        foreach ($xml->map as $mapElement) {
            $mapping[self::SECTION_MAPS][] = $this->parseXmlMap($mapElement);
        }

        // Handle <tables> container (recommended) and/or direct <table> elements.
        // Both are cumulative (like <param>).
        $allTables = [];
        if (isset($xml->tables)) {
            foreach ($xml->tables->table as $table) {
                $allTables[] = $table;
            }
        }
        foreach ($xml->table as $table) {
            $allTables[] = $table;
        }
        foreach ($allTables as $table) {
            $tableName = (string) ($table['name'] ?? $table['code'] ?? '');
            if (!$tableName) {
                continue;
            }
            // New format: <entry key="a">Value</entry>
            if (isset($table->entry)) {
                foreach ($table->entry as $entry) {
                    $key = (string) ($entry['key'] ?? '');
                    if (strlen($key)) {
                        $mapping['tables'][$tableName][$key] = (string) $entry;
                    }
                }
            }
            // Legacy format: <list><term code="a">Value</term></list>
            elseif (isset($table->list)) {
                foreach ($table->list->term as $term) {
                    $termCode = (string) $term['code'];
                    if (strlen($termCode)) {
                        $mapping['tables'][$tableName][$termCode] = (string) $term;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Parse a single xml map element.
     */
    protected function parseXmlMap(\SimpleXMLElement $element): array
    {
        $map = [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => []];

        // Parse from element.
        if (isset($element->from)) {
            $from = $element->from;
            if (!empty($from['xpath'])) {
                $map[self::MAP_FROM]['querier'] = 'xpath';
                $map[self::MAP_FROM]['path'] = (string) $from['xpath'];
            } elseif (!empty($from['jsdot'])) {
                $map[self::MAP_FROM]['querier'] = 'jsdot';
                $map[self::MAP_FROM]['path'] = (string) $from['jsdot'];
            } elseif (!empty($from['jsonpath'])) {
                $map[self::MAP_FROM]['querier'] = 'jsonpath';
                $map[self::MAP_FROM]['path'] = (string) $from['jsonpath'];
            } elseif (!empty($from['jmespath'])) {
                $map[self::MAP_FROM]['querier'] = 'jmespath';
                $map[self::MAP_FROM]['path'] = (string) $from['jmespath'];
            }
        }

        // Parse to element.
        if (isset($element->to)) {
            $to = $element->to;
            $map[self::MAP_TO]['field'] = (string) ($to['field'] ?? '');

            if (!empty($to['datatype'])) {
                $map[self::MAP_TO]['datatype'] = explode(' ', (string) $to['datatype']);
            }
            if (!empty($to['language'])) {
                $map[self::MAP_TO]['language'] = (string) $to['language'];
            }
            if (isset($to['visibility'])) {
                $map[self::MAP_TO]['is_public'] = (string) $to['visibility'] !== 'private';
            }

            if ($map[self::MAP_TO]['field']) {
                $propertyId = $this->easyMeta->propertyId($map[self::MAP_TO]['field']);
                if ($propertyId) {
                    $map[self::MAP_TO]['property_id'] = $propertyId;
                }
            }
        }

        // Parse mod element.
        if (isset($element->mod)) {
            $mod = $element->mod;
            if (!empty($mod['raw'])) {
                $map[self::MAP_MOD]['raw'] = (string) $mod['raw'];
            }
            if (!empty($mod['val'])) {
                $map[self::MAP_MOD]['val'] = (string) $mod['val'];
            }
            if (!empty($mod['prepend'])) {
                $map[self::MAP_MOD]['prepend'] = (string) $mod['prepend'];
            }
            if (!empty($mod['pattern'])) {
                $patternMod = $this->parsePattern((string) $mod['pattern']);
                $map[self::MAP_MOD] = array_merge($map[self::MAP_MOD], $patternMod);
            }
            if (!empty($mod['append'])) {
                $map[self::MAP_MOD]['append'] = (string) $mod['append'];
            }
        }

        return $map;
    }

    /**
     * Parse a pre-normalized array mapping.
     *
     * All sections are optional. If info is missing, label defaults to the
     * current mapping name (consistent with INI and XML parsing).
     */
    protected function parseNormalizedArray(array $input, array $options): array
    {
        $mapping = self::EMPTY_MAPPING;

        // Info section is optional (consistent with INI and XML formats).
        if (isset($input['info']) && is_array($input['info'])) {
            // Scalar info keys.
            foreach (['label', 'from', 'to', 'querier', 'mapper', 'example'] as $key) {
                if (!empty($input['info'][$key]) && is_string($input['info'][$key])) {
                    $mapping['info'][$key] = $input['info'][$key];
                }
            }
            // Array info keys.
            if (!empty($input['info']['preprocess']) && is_array($input['info']['preprocess'])) {
                $mapping['info']['preprocess'] = array_values(array_filter(
                    $input['info']['preprocess'],
                    fn($v) => is_string($v) && strlen($v)
                ));
            }
        }
        $mapping['info']['label'] = $mapping['info']['label'] ?? $this->currentName;

        if (isset($input['params']) && is_array($input['params'])) {
            $mapping['params'] = $input['params'];
        }

        if (isset($input['tables']) && is_array($input['tables'])) {
            $mapping['tables'] = $input['tables'];
        }

        foreach (self::MAP_SECTIONS as $section) {
            if (isset($input[$section]) && is_array($input[$section])) {
                $options['section'] = $section;
                $mapping[$section] = $this->normalizeMaps($input[$section], $options);
            }
        }

        return $this->finalizeMapping($mapping);
    }

    /**
     * Parse a simple list of maps (e.g., spreadsheet headers).
     */
    protected function parseMapList(array $maps, array $options): array
    {
        $mapping = self::EMPTY_MAPPING;
        $mapping[self::SECTION_INFO]['label'] = $options['label'] ?? $this->currentName;
        $mapping[self::SECTION_INFO]['querier'] = 'index';

        $options['section'] = self::SECTION_MAPS;
        $mapping[self::SECTION_MAPS] = $this->normalizeMaps($maps, $options);

        // No finalize needed here - parseMapList only creates maps section.
        return $mapping;
    }

    /**
     * Normalize a map from a string.
     */
    protected function normalizeMapFromString(string $map, array $options): array
    {
        $map = trim($map);
        if (!$map) {
            return [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => []];
        }

        // Xml string.
        if (mb_substr($map, 0, 1) === '<') {
            try {
                $xml = new \SimpleXMLElement($map);
                return $this->parseXmlMap($xml);
            } catch (\Exception $e) {
                return [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => [], 'has_error' => true];
            }
        }

        // Ini-style string.
        $equalsPos = mb_strpos($map, '=');
        if ($equalsPos === false) {
            return [
                self::MAP_FROM => isset($options['index']) ? ['index' => $options['index']] : [],
                self::MAP_TO => $this->parseFieldSpec($map),
                self::MAP_MOD => [],
            ];
        }

        $from = trim(mb_substr($map, 0, $equalsPos));
        $to = trim(mb_substr($map, $equalsPos + 1));

        return $this->normalizeMapFromIniParts($from, $to, $options);
    }

    /**
     * Normalize a map from an array.
     */
    protected function normalizeMapFromArray(array $map, array $options): array
    {
        $result = [self::MAP_FROM => [], self::MAP_TO => [], self::MAP_MOD => []];

        if (isset($map[self::MAP_FROM])) {
            if (is_string($map[self::MAP_FROM])) {
                $result[self::MAP_FROM]['path'] = $map[self::MAP_FROM];
            } elseif (is_array($map[self::MAP_FROM])) {
                $result[self::MAP_FROM] = $map[self::MAP_FROM];
            }
        }

        if (isset($map[self::MAP_TO])) {
            if (is_string($map[self::MAP_TO])) {
                $result[self::MAP_TO] = $this->parseFieldSpec($map[self::MAP_TO]);
            } elseif (is_array($map[self::MAP_TO])) {
                $result[self::MAP_TO] = $map[self::MAP_TO];
                if (!empty($result[self::MAP_TO]['field']) && empty($result[self::MAP_TO]['property_id'])) {
                    $propertyId = $this->easyMeta->propertyId($result[self::MAP_TO]['field']);
                    if ($propertyId) {
                        $result[self::MAP_TO]['property_id'] = $propertyId;
                    }
                }
            }
        }

        if (isset($map[self::MAP_MOD])) {
            if (is_string($map[self::MAP_MOD])) {
                $result[self::MAP_MOD] = $this->parsePattern($map[self::MAP_MOD]);
            } elseif (is_array($map[self::MAP_MOD])) {
                $result[self::MAP_MOD] = $map[self::MAP_MOD];
                // Parse pattern if present but not yet parsed.
                if (!empty($map[self::MAP_MOD]['pattern'])
                    && empty($map[self::MAP_MOD]['replace'])
                    && empty($map[self::MAP_MOD]['filters'])
                ) {
                    $patternMod = $this->parsePattern((string) $map[self::MAP_MOD]['pattern']);
                    $result[self::MAP_MOD] = array_merge($result[self::MAP_MOD], $patternMod);
                }
            }
        }

        if (isset($options['index']) && empty($result[self::MAP_FROM]['path'])) {
            $result[self::MAP_FROM]['index'] = $options['index'];
        }

        return $result;
    }
}
