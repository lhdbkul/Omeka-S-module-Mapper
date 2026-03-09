<?php declare(strict_types=1);

/**
 * Mapper - Transforms data from source using mapping configurations.
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

use Common\Stdlib\EasyMeta;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Laminas\Log\Logger;
use Laminas\Mvc\I18n\Translator;
use Omeka\Api\Manager as ApiManager;
use SimpleXMLElement;

class Mapper
{
    use FilterTrait;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * JmesPath environment, if available.
     *
     * @var object|null
     */
    protected $jmesPathEnv;

    /**
     * JsonPath querier, if available.
     *
     * @var object|null
     */
    protected $jsonPathQuerier;

    /**
     * @var \Mapper\Stdlib\MapperConfig
     */
    protected $mapperConfig;

    /**
     * @var \Laminas\Mvc\I18n\Translator
     */
    protected $translator;

    /**
     * Current mapping name.
     *
     * @var string|null
     */
    protected $mappingName;

    /**
     * Variables available during mapping (e.g., pagination params).
     * @var array
     */
    protected $variables = [];

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        Logger $logger,
        MapperConfig $mapperConfig,
        Translator $translator,
    ) {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->mapperConfig = $mapperConfig;
        $this->translator = $translator;

        // Initialize optional queriers if available.
        if (class_exists('JmesPath\Env')) {
            $this->jmesPathEnv = new \JmesPath\Env();
        }
        if (class_exists('Flow\JSONPath\JSONPath')) {
            $this->jsonPathQuerier = new \Flow\JSONPath\JSONPath();
        }
    }

    /**
     * Get the meta mapper and optionally set or load a mapping.
     *
     * @param string|null $mappingName Name identifier for the mapping.
     * @param array|string|null $mappingOrRef Mapping content or reference.
     */
    public function __invoke(?string $mappingName = null, $mappingOrRef = null): self
    {
        if ($mappingName) {
            if ($mappingOrRef === null) {
                $this->setMappingName($mappingName);
            } else {
                $this->setMapping($mappingName, $mappingOrRef);
            }
        }
        return $this;
    }

    /**
     * Get the underlying MapperConfig instance.
     */
    public function getMapperConfig(): MapperConfig
    {
        return $this->mapperConfig;
    }

    /**
     * Set the current mapping name (must already be loaded in config).
     */
    public function setMappingName(string $mappingName): self
    {
        $this->mappingName = $mappingName;
        return $this;
    }

    /**
     * Get the current mapping name.
     */
    public function getMappingName(): ?string
    {
        return $this->mappingName;
    }

    /**
     * Load and set a mapping by name.
     *
     * @param string $mappingName Name identifier.
     * @param array|string $mappingOrRef Mapping content or reference.
     */
    public function setMapping(string $mappingName, $mappingOrRef): self
    {
        $this->mappingName = $mappingName;
        $this->mapperConfig->__invoke($mappingName, $mappingOrRef);
        return $this;
    }

    /**
     * Get the current mapping configuration.
     */
    public function getMapping(): ?array
    {
        return $this->mapperConfig->getMapping($this->mappingName);
    }

    /**
     * Set variables available during mapping.
     *
     * When static variables (url, filename) are set, this also triggers
     * evaluation of static params in the current mapping.
     */
    public function setVariables(array $variables): self
    {
        $this->variables = $variables;

        // Evaluate static params if static variables are provided.
        $hasStaticVars = !empty($variables['url']) || !empty($variables['filename']);
        if ($hasStaticVars && $this->mappingName) {
            $this->mapperConfig->evaluateStaticParams($variables, $this->mappingName);
        }

        return $this;
    }

    /**
     * Set a single variable.
     */
    public function setVariable(string $name, $value): self
    {
        $this->variables[$name] = $value;
        return $this;
    }

    /**
     * Get current variables.
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * Convert data into an Omeka resource array using the current mapping.
     *
     * @param array|SimpleXMLElement $data Source data to transform.
     * @return array Transformed resource data.
     */
    public function convert($data): array
    {
        $mapping = $this->mapperConfig->getMapping($this->mappingName);
        if (!$mapping) {
            return [];
        }

        $result = [];

        // All maps (including former "default") are now in 'maps' section.
        // Default maps are detected by the absence of a source path.
        if (is_array($data)) {
            $result = $this->convertSectionArray('maps', $result, $data);
        } elseif ($data instanceof SimpleXMLElement) {
            $result = $this->convertSectionXml('maps', $result, $data);
        }

        return $result;
    }

    /**
     * Convert a string value using a map definition.
     *
     * Useful for simple transformations like spreadsheet cells.
     */
    public function convertString(?string $value, array $map = []): string
    {
        if ($value === null) {
            return '';
        }

        if (empty($map['mod'])) {
            return $value;
        }

        $mod = $map['mod'];

        if (isset($mod['raw'])) {
            return (string) $mod['raw'];
        }

        if (isset($mod['val'])) {
            return (string) $mod['val'];
        }

        $this->setVariable('value', $value);
        $result = $this->convertTargetToStringArray(['path' => null], $mod, null, 'value');

        if ($result === null || !strlen($result)) {
            return '';
        }

        return ($mod['prepend'] ?? '') . $result . ($mod['append'] ?? '');
    }

    /**
     * Extract a value from data using a path and querier.
     *
     * @param array|SimpleXMLElement|DOMDocument $data Source data.
     * @param string $path Query path (XPath, jsdot, etc.).
     * @param string $querier Querier: xpath, jsdot, jmespath, jsonpath, index.
     * @return mixed Extracted value(s).
     */
    public function extractValue($data, string $path, string $querier = 'jsdot')
    {
        if ($data instanceof SimpleXMLElement || $data instanceof DOMDocument) {
            return $this->xpathQuery($data, $path);
        }

        if (!is_array($data)) {
            return null;
        }

        switch ($querier) {
            case 'index':
                return $data[$path] ?? null;

            case 'jmespath':
                if ($this->jmesPathEnv) {
                    return $this->jmesPathEnv->search($path, $data);
                }
                return null;

            case 'jsonpath':
                if ($this->jsonPathQuerier) {
                    $querier = new \Flow\JSONPath\JSONPath($data);
                    return $querier->find($path)->getData();
                }
                return null;

            case 'jsdot':
            default:
                $flatData = $this->flatArray($data);
                return $flatData[$path] ?? null;
        }
    }

    /**
     * Extract a sub-value from data using a path, with a default if not found.
     *
     * This is similar to extractValue() but returns a default value when the
     * path is not found, and supports nested path traversal for arrays.
     *
     * @param array|object $data Source data (array or object).
     * @param string $path Dot-notation path (e.g., "data.items" or "results.0").
     * @param mixed $default Default value to return if path not found.
     * @return mixed The extracted value or default.
     *
     * @todo This method is used only for BulkImport JsonReader extract for now. Clarify use, check if it is really needed or how to make it more generic.
     */
    public function extractSubValue($data, string $path, $default = null)
    {
        if (empty($path)) {
            return $data;
        }

        // Convert object to array if needed.
        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }

        if (!is_array($data)) {
            return $default;
        }

        // Navigate through the path.
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
            } else {
                return $default;
            }
        }

        return $current;
    }

    /**
     * Convert a mapping section setting to a string using variable substitution.
     *
     * This retrieves a setting from the current mapping configuration and
     * processes it through pattern replacement using the current variables.
     *
     * @param string $section The section name (e.g., "params", "info").
     * @param string $key The setting key within the section.
     * @param mixed $default Default value if setting not found.
     * @return string|null The processed string value or null.
     *
     * @todo This method is used only for BulkImport JsonReader extract for now. Clarify use, check if it is really needed or how to make it more generic.
     */
    public function convertToString(string $section, string $key, $default = null): ?string
    {
        $setting = $this->mapperConfig->getSectionSetting($section, $key, $default);

        if ($setting === null || $setting === '') {
            // Only return default if it's a string or null.
            return is_string($default) ? $default : null;
        }

        // If the setting is an array, it cannot be converted to a string.
        if (is_array($setting)) {
            return is_string($default) ? $default : null;
        }

        // If it's already a simple string without placeholders, return as-is.
        if (is_string($setting) && strpos($setting, '{') === false) {
            return $setting;
        }

        // Process pattern replacement with current variables.
        if (is_string($setting)) {
            $replace = [];

            // Add variable replacements ({{ variable }} format).
            foreach ($this->variables as $name => $value) {
                if (is_scalar($value)) {
                    $replace["{{ $name }}"] = $value;
                    $replace["{{$name}}"] = $value;
                    // Also support single-brace {variable} for simple cases.
                    $replace["{$name}"] = $value;
                }
            }

            return strtr($setting, $replace);
        }

        return is_scalar($setting) ? (string) $setting : null;
    }

    /**
     * Flatten a nested array into dot-notation keys.
     *
     * Example: ['a' => ['b' => 'c']] => ['a.b' => 'c']
     */
    public function flatArray(?array $array): array
    {
        if (empty($array)) {
            return [];
        }

        // Quick check for already flat arrays.
        if (array_filter($array, 'is_scalar') === $array) {
            return $array;
        }

        $flatArray = [];
        $this->flatArrayRecursive($array, $flatArray);
        return $flatArray;
    }

    /**
     * Convert mappings from a section for array data.
     *
     * This method should be used when a mapping source ("from") is used
     * multiple times.
     *
     * Maps without a source path (from.path) are treated as "default" maps
     * and are applied without extracting data from the source.
     */
    protected function convertSectionArray(string $section, array $resource, ?array $data): array
    {
        $maps = $this->mapperConfig->getSection($section);
        if (empty($maps)) {
            return $resource;
        }

        $flatData = null;
        $fields = null;

        foreach ($maps as $map) {
            $to = $map['to'] ?? [];
            if (empty($to['field'])) {
                continue;
            }

            $mod = $map['mod'] ?? [];

            // Raw value - use directly.
            if (isset($mod['raw']) && strlen($mod['raw'])) {
                $this->finalizeConversion($resource, $to, [$mod['raw']]);
                continue;
            }

            $from = $map['from'] ?? [];
            // Support both 'path' and 'index' - index is used for spreadsheet column names.
            $fromPath = $from['path'] ?? $from['index'] ?? null;
            $querier = $from['querier']
                ?? $this->mapperConfig->getSectionSetting('info', 'querier')
                ?? 'jsdot';

            $prepend = $mod['prepend'] ?? '';
            $append = $mod['append'] ?? '';
            $val = $mod['val'] ?? '';

            // Default map - no source path, but pattern can access all data.
            if (empty($fromPath)) {
                // Lazy-load flat data for pattern replacements.
                if ($flatData === null && $data) {
                    $flatData = $this->flatArray($data);
                }
                $this->setVariable('value', null);
                $converted = $this->convertTargetToStringArray($from, $mod, $flatData, $querier);
                if ($converted === null || $converted === '') {
                    continue;
                }
                $result = strlen($val) ? $val : $prepend . $converted . $append;
                $this->finalizeConversion($resource, $to, [$result]);
                continue;
            }

            // Lazy-load flat data and fields.
            if ($flatData === null || $fields === null) {
                $flatData = $flatData ?? $this->flatArray($data);
                $fields = $fields ?? $this->extractFields($data);
            }

            $values = $this->extractValuesArray($data, $flatData, $fields, $fromPath, $querier);
            if (empty($values)) {
                continue;
            }

            $results = [];
            foreach ($values as $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $this->setVariable('value', $value);

                // Build source context for pattern replacements.
                $source = ($querier === 'index')
                    ? [$fromPath => $value]
                    : array_merge($flatData, [$fromPath => $value]);

                $converted = $this->convertTargetToStringArray($from, $mod, $source, $querier);
                if ($converted === null || $converted === '') {
                    continue;
                }

                $results[] = strlen($val) ? $val : $prepend . $converted . $append;
            }

            if (!empty($results)) {
                $this->finalizeConversion($resource, $to, $results);
            }
        }

        return $resource;
    }

    /**
     * Convert mappings from a section for xml data.
     *
     * This method should be used when a mapping source ("from") is used
     * multiple times.
     *
     * Maps without a source path (from.path) are treated as "default" maps
     * and are applied without extracting data from the source.
     */
    protected function convertSectionXml(string $section, array $resource, SimpleXMLElement $xml): array
    {
        $maps = $this->mapperConfig->getSection($section);
        if (empty($maps)) {
            return $resource;
        }

        // TODO Important: see c14n(), that allows to filter document directly with a list of xpath.

        // Convert to DOM for full xpath support.
        $dom = dom_import_simplexml($xml);
        $doc = new DOMDocument();
        $doc->appendChild($doc->importNode($dom, true));

        foreach ($maps as $map) {
            $to = $map['to'] ?? [];
            if (empty($to['field'])) {
                continue;
            }

            $mod = $map['mod'] ?? [];
            $from = $map['from'] ?? [];
            $fromPath = $from['path'] ?? null;

            // Raw value - use directly.
            if (isset($mod['raw']) && strlen($mod['raw'])) {
                $this->finalizeConversion($resource, $to, [$mod['raw']]);
                continue;
            }

            // Check if output should be XML content.
            $outputAsXml = !empty($to['datatype']) && reset($to['datatype']) === 'xml';

            $prepend = $mod['prepend'] ?? '';
            $append = $mod['append'] ?? '';
            $val = $mod['val'] ?? '';

            // Default map - no source path, but pattern can query the document.
            if (empty($fromPath)) {
                $this->setVariable('value', null);
                $converted = $this->convertTargetToStringXml($from, $mod, $doc, null, $outputAsXml);
                if ($converted === null || $converted === '') {
                    continue;
                }
                $result = strlen($val) ? $val : $prepend . $converted . $append;
                $this->finalizeConversion($resource, $to, [$result]);
                continue;
            }

            $nodes = $this->xpathQuery($doc, $fromPath);
            if (empty($nodes)) {
                continue;
            }

            $results = [];
            foreach ($nodes as $node) {
                $this->setVariable('value', $node);
                $converted = $this->convertTargetToStringXml($from, $mod, $doc, $node, $outputAsXml);
                if ($converted === null || $converted === '') {
                    continue;
                }
                $results[] = strlen($val) ? $val : $prepend . $converted . $append;
            }

            if (!empty($results)) {
                $this->finalizeConversion($resource, $to, $results);
            }
        }

        return $resource;
    }

    /**
     * Extract values from array data using the specified querier.
     */
    protected function extractValuesArray(array $data, array $flatData, array $fields, string $path, string $querier): array
    {
        switch ($querier) {
            case 'jmespath':
                if ($this->jmesPathEnv) {
                    $values = $this->jmesPathEnv->search($path, $data);
                    return $this->normalizeValues($values);
                }
                return [];

            case 'jsonpath':
                if ($this->jsonPathQuerier) {
                    $querier = new \Flow\JSONPath\JSONPath($data);
                    $values = $querier->find($path)->getData();
                    return $this->normalizeValues($values);
                }
                return [];

            case 'index':
                $values = $data[$path] ?? [];
                return $this->normalizeValues($values);

            case 'jsdot':
            default:
                // Check direct path in flat data.
                if (array_key_exists($path, $flatData)) {
                    return $this->normalizeValues($flatData[$path]);
                }

                // Check for fields pattern "fields[].key".
                if (mb_substr($path, 0, 9) === 'fields[].') {
                    $fieldKey = mb_substr($path, 9);
                    return $this->normalizeValues($fields[$fieldKey] ?? []);
                }

                return [];
        }
    }

    /**
     * Normalize extracted values to an array.
     */
    protected function normalizeValues($values): array
    {
        if ($values === null || $values === '' || $values === []) {
            return [];
        }
        return is_array($values) ? array_values($values) : [$values];
    }

    /**
     * Convert a from/mod spec to a string for array data.
     *
     * Example:
     * ```php
     * $this->variables = [
     *     'endpoint' => 'https://example.com',
     *     // Set for current value; default output when no pattern.
     *     'value' => 'xxx',
     * ];
     * $from = 'yyy';
     * $mod = [
     *     'pattern' => '{{ endpoint }}/api{{itemLink}}',
     *     // The following keys are automatically created from the pattern.
     *     'replace' => [ '{{itemLink}}' ]
     *     'filters' => [ '{{ endpoint }}' ],
     * ];
     * $data = [
     *     'itemLink' => '/id/150',
     * ];
     * $output = 'https://example.com/api/id/1850'
     * ```
     *
     * @param array $from The key, or array with "path", where to get the
     *   data.
     * @param array|string $mod If array, contains the pattern to use, else the
     *   static value itself.
     * @param array $data The resource from which extract the data, if needed,
     *   and any other value.
     * @param string $querier "jsdot", "jmespath", "jsonpath", "index", "value".
     * @return string The converted value. Without pattern, return the key
     *   "value" from the variables.
     */
    protected function convertTargetToStringArray(
        array $from,
        array $mod,
        ?array $data,
        string $querier
    ): ?string {
        $mod = $mod['mod'] ?? $mod;

        // Get the source value.
        $fromPath = $from['path'] ?? null;
        $fromValue = $this->variables['value'] ?? null;

        if ($fromPath !== null && $data !== null) {
            switch ($querier) {
                case 'index':
                    $fromValue = $data[$fromPath] ?? $fromValue;
                    break;
                case 'jmespath':
                    if ($this->jmesPathEnv) {
                        $fromValue = $this->jmesPathEnv->search($fromPath, $data) ?? $fromValue;
                    }
                    break;
                case 'jsonpath':
                    if ($this->jsonPathQuerier) {
                        $q = new \Flow\JSONPath\JSONPath($data);
                        $fromValue = $q->find($fromPath)->getData() ?? $fromValue;
                    }
                    break;
                case 'jsdot':
                default:
                    $fromValue = $data[$fromPath] ?? $fromValue;
                    break;
            }
        }

        // No pattern - return value directly.
        if (!isset($mod['pattern']) || !strlen($mod['pattern'])) {
            if ($fromValue === null) {
                return null;
            }
            if (is_scalar($fromValue)) {
                return (string) $fromValue;
            }
            return (string) reset($fromValue);
        }

        // Handle raw/val shortcuts.
        if (isset($mod['raw']) && strlen($mod['raw'])) {
            return (string) $mod['raw'];
        }
        if (isset($mod['val']) && strlen($mod['val'])) {
            return (string) $mod['val'];
        }

        // Build replacements with strict syntax distinction:
        // - {path} → source data only (PSR-3 style)
        // - {{ variable }} → context variables only (Twig-style)
        $replace = [];

        // First, add all context variables ({{ variable }} format).
        foreach ($this->variables as $name => $value) {
            if ($value instanceof DOMNode) {
                $replace["{{ $name }}"] = (string) $value->nodeValue;
            } elseif (is_scalar($value)) {
                $replace["{{ $name }}"] = $value;
            }
        }

        // Then, add source data replacements ({path} format only).
        if (!empty($mod['replace']) && $data) {
            foreach ($mod['replace'] as $wrappedQuery) {
                // Only process single-brace {path} for data lookup.
                // Double-brace {{ variable }} uses variables above.
                if (mb_substr($wrappedQuery, 0, 2) === '{{') {
                    continue;
                }
                // Extract path from {path}.
                $query = $this->extractPathFromBraces($wrappedQuery);
                $replace[$wrappedQuery] = $data[$query] ?? '';
            }
        }

        // Check if all source data replacements are empty (semi-strict mode).
        // If so, skip this value entirely.
        if (!empty($mod['replace'])) {
            $allEmpty = true;
            foreach ($mod['replace'] as $wrappedQuery) {
                if (mb_substr($wrappedQuery, 0, 2) === '{{') {
                    continue; // Skip context variables.
                }
                if (isset($replace[$wrappedQuery]) && strlen($replace[$wrappedQuery])) {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                return null;
            }
        }

        // Apply simple replacements.
        $result = strtr($mod['pattern'], $replace);

        // Apply filter expressions.
        if (!empty($mod['filters'])) {
            $result = $this->applyFilters(
                $result,
                $this->variables,
                $mod['filters'],
                $mod['filters_has_replace'] ?? [],
                $replace
            );
        }

        // Trim result to avoid leading/trailing whitespace from missing values.
        $result = trim($result);

        // Verify at least one replacement was made.
        if (!$this->hasReplacement($fromValue, $result, $mod)) {
            return null;
        }

        return $result;
    }

    /**
     * Convert a from/mod spec to a string for XML data.
     *
     * Example:
     * ```php
     * $this->variables = [
     *     'endpoint' => 'https://example.com',
     *     // Set for current value; default output when no pattern.
     *     'value' => 'xxx',
     * ];
     * $from = 'yyy';
     * $mod = [
     *     'pattern' => '{{ endpoint }}/api{{itemLink}}',
     *     // The following keys are automatically created from the pattern.
     *     'replace' => [ '{{itemLink}}' ]
     *     'filters' => [ '{{ endpoint }}' ],
     * ];
     * $data = [
     *     'itemLink' => '/id/150',
     * ];
     * $output = 'https://example.com/api/id/150'
     * ```
     *
     * @param string|array $from The key, or an array with key "path", where to
     *   get the data.
     * @param array|string $mod If array, contains the pattern to use, else the
     *   static value itself.
     * @param \DOMDocument|\SimpleXMLElement $doc The resource from which
     *   extract the data, if needed.
     * @param \DOMNode|string $fromValue
     * @param bool $outputAsXml When set, get the xml content, not the xml node
     *   value.
     * @return string The converted value. Without pattern, return the key
     *   "value" from the variables.
     */
    protected function convertTargetToStringXml(
        array $from,
        array $mod,
        ?DOMDocument $doc,
        $fromValue,
        bool $outputAsXml = false
    ): ?string {
        // TODO c14n() allows to filter nodes with xpath.

        $mod = $mod['mod'] ?? $mod;
        $fromPath = $from['path'] ?? null;

        // Get source value if not provided.
        if ($fromValue === null && $fromPath && $doc) {
            $nodes = $this->xpathQuery($doc, $fromPath);
            $fromValue = !empty($nodes) ? reset($nodes) : null;
        }

        // Normalize value for output.
        $stringValue = null;
        if ($fromValue === null) {
            $stringValue = null;
        } elseif (is_scalar($fromValue)) {
            $stringValue = (string) $fromValue;
        } elseif ($fromValue instanceof DOMNode) {
            $stringValue = $outputAsXml ? $fromValue->C14N() : (string) $fromValue->nodeValue;
        } elseif ($fromValue instanceof SimpleXMLElement) {
            // Not used any more. SimpleXml doesn't support context or subquery.
            $stringValue = $outputAsXml ? $fromValue->saveXML() : (string) $fromValue;
        }

        // No pattern - return value directly.
        if (!isset($mod['pattern']) || !strlen($mod['pattern'])) {
            return $stringValue;
        }

        // Special pattern for full XML output.
        if ($mod['pattern'] === '{{ xml }}' && $outputAsXml) {
            return $stringValue;
        }

        // Handle raw/val shortcuts.
        if (isset($mod['raw']) && strlen($mod['raw'])) {
            return (string) $mod['raw'];
        }
        if (isset($mod['val']) && strlen($mod['val'])) {
            return (string) $mod['val'];
        }

        // Build replacements with strict syntax distinction:
        // - {xpath} → XPath query on document (PSR-3 style)
        // - {{ variable }} → context variables only (Twig-style)
        $replace = [];

        // First, add all context variables ({{ variable }} format).
        foreach ($this->variables as $name => $value) {
            if ($value instanceof DOMNode) {
                $replace["{{ $name }}"] = (string) $value->nodeValue;
            } elseif (is_scalar($value)) {
                $replace["{{ $name }}"] = $value;
            }
        }

        // Then, add XPath query replacements ({xpath} format only).
        if (!empty($mod['replace']) && $doc) {
            $contextNode = $fromValue instanceof DOMNode ? $fromValue : null;
            foreach ($mod['replace'] as $wrappedQuery) {
                // Only process single-brace {xpath} for XPath queries.
                // Double-brace {{ variable }} uses variables above.
                if (mb_substr($wrappedQuery, 0, 2) === '{{') {
                    continue;
                }
                // Extract xpath query from {xpath}.
                $query = $this->extractPathFromBraces($wrappedQuery);
                $nodes = $this->xpathQuery($doc, $query, $contextNode);
                if (!empty($nodes)) {
                    $firstNode = reset($nodes);
                    $replace[$wrappedQuery] = $firstNode instanceof DOMNode
                        ? (string) $firstNode->nodeValue
                        : (string) $firstNode;
                } else {
                    $replace[$wrappedQuery] = '';
                }
            }
        }

        // Check if all source data replacements are empty (semi-strict mode).
        // If so, skip this value entirely.
        if (!empty($mod['replace'])) {
            $allEmpty = true;
            foreach ($mod['replace'] as $wrappedQuery) {
                if (mb_substr($wrappedQuery, 0, 2) === '{{') {
                    continue; // Skip context variables.
                }
                if (isset($replace[$wrappedQuery]) && strlen($replace[$wrappedQuery])) {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                return null;
            }
        }

        // Apply simple replacements.
        $result = strtr($mod['pattern'], $replace);

        // Apply filter expressions.
        if (!empty($mod['filters'])) {
            $result = $this->applyFilters(
                $result,
                $this->variables,
                $mod['filters'],
                $mod['filters_has_replace'] ?? [],
                $replace
            );
        }

        // Trim result to avoid leading/trailing whitespace from missing values.
        $result = trim($result);

        // Verify at least one replacement was made.
        $checkValue = $fromValue instanceof DOMNode ? (string) $fromValue->nodeValue : $stringValue;
        if (!$this->hasReplacement($checkValue, $result, $mod)) {
            return null;
        }

        return $result;
    }

    /**
     * Check if a pattern transformation resulted in at least one replacement.
     *
     * Nevertheless, if the pattern does not contain any static string, the
     * check is skipped.
     *
     * It avoids to return something when there is no transformation or no
     * value. For example "pattern for {{ value|trim }} with {{/xpath}}",
     * if there is no value and no source record data, the transformation
     * returns something, the raw text of the pattern, but this is useless.
     *
     * This method does not check for transformation with "raw" or "val".
     */
    protected function hasReplacement($value, ?string $result, array $mod): bool
    {
        // Result must have content.
        if ($result === null || !strlen($result)) {
            return false;
        }

        if (empty($mod['pattern'])) {
            return false;
        }

        $allReplacements = array_merge(
            ['{{ value }}'],
            $mod['replace'] ?? [],
            $mod['filters'] ?? []
        );

        // If pattern is only replacements (no static text), consider it valid.
        // Use strtr() which replaces simultaneously, avoiding order issues.
        $staticText = trim(strtr($mod['pattern'], array_fill_keys($allReplacements, '')));
        if (!strlen($staticText)) {
            return true;
        }

        // Default maps have no value but combine source data via {path} replacements.
        // If we have replacements and result differs from pattern, it's valid.
        if ($value === null) {
            return $mod['pattern'] !== $result;
        }

        // Check if result differs from pattern with all replacements removed.
        return strtr((string) $value, array_fill_keys($allReplacements, '')) !== $result;
    }

    /**
     * Extract path from braced expression.
     *
     * Handles both PSR-3 style {path} and double-brace {{path}} or {{ path }}.
     */
    protected function extractPathFromBraces(string $expression): string
    {
        $path = trim($expression);

        // Handle double braces first.
        if (mb_substr($path, 0, 2) === '{{' && mb_substr($path, -2) === '}}') {
            $path = mb_substr($path, 2, -2);
        } elseif (mb_substr($path, 0, 1) === '{' && mb_substr($path, -1) === '}') {
            // Single braces.
            $path = mb_substr($path, 1, -1);
        }

        return trim($path);
    }

    /**
     * Finalize conversion and append results to resource.
     *
     * Whatever the field type is, the output is always an array of that type or
     * null. In particular, a list allows to manage multiple identifiers.
     *
     * When the output type is an array, the key "__value" is appended, except
     * if the processor output an array directly, in particular for an xml.
     *
     * There is no early deduplicate or simplification in order to manage
     * complex mapping.
     */
    protected function finalizeConversion(array &$resource, array $to, ?array $results): void
    {
        static $translate = null;

        // Do not fill field here: it may be used for advanced update.
        if ($results === null || empty($results)) {
            return;
        }

        if ($translate === null) {
            $translate = [
                'false' => $this->translator->translate('false'),
                'no' => $this->translator->translate('no'),
                'off' => $this->translator->translate('off'),
                'true' => $this->translator->translate('true'),
                'yes' => $this->translator->translate('yes'),
                'on' => $this->translator->translate('on'),
            ];
        }

        $field = $to['field'];
        $fieldType = $to['field_type'] ?? null;
        unset($to['field'], $to['field_type']);

        // Convert results based on field type.
        switch ($fieldType) {
            default:
                // Nothing to do: keep result as is (string or array).
                // In particular for entities, that should be checked.
                break;

            case 'skip':
                return;

            case 'boolean':
            case 'booleans':
                $trueValues = [true, 1, '1', 'true', 'yes', 'on', $translate['true'], $translate['yes'], $translate['on']];
                $falseValues = [false, 0, '0', 'false', 'no', 'off', $translate['false'], $translate['no'], $translate['off']];
                $results = array_map(function ($v) use ($trueValues, $falseValues) {
                    $lower = is_string($v) ? strtolower($v) : $v;
                    if (in_array($lower, $trueValues, true)) {
                        return true;
                    }
                    if (in_array($lower, $falseValues, true)) {
                        return false;
                    }
                    return null;
                }, $results);
                break;

            case 'integer':
            case 'integers':
                $results = array_map('intval', $results);
                break;

            case 'string':
            case 'strings':
                $results = array_map('strval', $results);
                break;

            case 'datetime':
            case 'datetimes':
                // Date time is designed to fill sql date time (created or
                // modified), so fill as string.
                $results = array_map(function ($v) {
                    return $v ? substr_replace('0000-00-00 00:00:00', $v, 0, strlen($v)) : null;
                }, $results);
                break;

            case 'array':
            case 'arrays':
                // Some processes like xml may prepare an array directly.
                // In that case, the value is not added as "__value".
                $results = array_map(function ($v) use ($to) {
                    if (is_array($v)) {
                        return $v + $to;
                    }
                    $data = $to;
                    $data['__value'] = $v;
                    return $data;
                }, $results);
                break;
        }

        // Convert simple values to array format with metadata from $to.
        // This ensures datatype, language, and visibility are preserved.
        // Skip conversion for string/strings field types - they need raw values
        // (e.g., file, o:source, ingest_url expect raw strings, not JSON-LD).
        if ($fieldType !== 'array' && $fieldType !== 'arrays'
            && $fieldType !== 'string' && $fieldType !== 'strings'
        ) {
            $datatypes = $to['datatype'] ?? [];
            $datatype = $datatypes[0] ?? null;
            $language = $to['language'] ?? null;
            $isPublic = $to['is_public'] ?? null;
            $hasMultipleDatatypes = count($datatypes) > 1;

            $results = array_map(function ($v) use ($datatypes, $datatype, $hasMultipleDatatypes, $language, $isPublic) {
                if (is_array($v)) {
                    // Already array format, just add missing metadata.
                    if ($datatype && !isset($v['type'])) {
                        if ($hasMultipleDatatypes) {
                            $v['datatype'] = $datatypes;
                        } else {
                            $v['type'] = $datatype;
                        }
                    }
                    if ($language && !isset($v['@language'])) {
                        $v['@language'] = $language;
                    }
                    if ($isPublic !== null && !isset($v['is_public'])) {
                        $v['is_public'] = $isPublic;
                    }
                    return $v;
                }

                // Convert string value to array format.
                $stringValue = (string) $v;
                $result = [];

                // When multiple datatypes are specified, pass
                // the list as candidates for the processor to
                // try in order. Don't fix the type yet.
                if ($hasMultipleDatatypes) {
                    $result['datatype'] = $datatypes;
                    $result['@value'] = $stringValue;
                } elseif ($datatype) {
                    $result['type'] = $datatype;
                    // Set value in appropriate key based on main type.
                    $mainType = $this->easyMeta->dataTypeMain($datatype);
                    if ($mainType === 'resource') {
                        $result['value_resource_id'] = (int) $stringValue;
                    } elseif ($mainType === 'uri') {
                        $result['@id'] = $stringValue;
                    } else {
                        $result['@value'] = $stringValue;
                    }
                } elseif (filter_var($stringValue, FILTER_VALIDATE_URL)) {
                    $result['type'] = 'uri';
                    $result['@id'] = $stringValue;
                } else {
                    $result['type'] = 'literal';
                    $result['@value'] = $stringValue;
                }

                if ($language) {
                    $result['@language'] = $language;
                }
                if ($isPublic !== null) {
                    $result['is_public'] = $isPublic;
                }

                return $result;
            }, $results);
        }

        // Append to resource.
        if (empty($resource[$field])) {
            $resource[$field] = $results;
        } else {
            $resource[$field] = array_merge(array_values($resource[$field]), $results);
        }

        // Deduplicate early, but revert to keep the last value, that overrides
        // previous ones.
        /*
        if (count($resource[$field]) > 1) {
            if (gettype(reset($resource[$field])) === 'array') {
                $resource[$field] = array_values(array_intersect_key($resource[$field], array_unique(array_map('serialize', $resource[$field]))));
            } else {
                $resource[$field] = array_unique($resource[$field]);
            }
        }
         */
    }

    /**
     * Extract fields for simplified lookups (like spreadsheet key-value pairs).
     */
    protected function extractFields(?array $data): array
    {
        if (empty($data)) {
            return [];
        }

        // Check if metadata are in a sub-array.
        // The list of fields simplifies left parts and manage multiple values.
        $fieldsKey = $this->mapperConfig->getSectionSetting('params', 'fields');
        if (!$fieldsKey) {
            return [];
        }

        $flatData = $this->flatArray($data);
        $fieldsKeyDot = $fieldsKey . '.';
        $fieldsKeyDotLength = mb_strlen($fieldsKeyDot);

        $fields = [];
        foreach ($flatData as $key => $value) {
            if (mb_substr((string) $key, 0, $fieldsKeyDotLength) === $fieldsKeyDot) {
                $parts = explode('.', mb_substr((string) $key, $fieldsKeyDotLength), 2);
                if (isset($parts[1])) {
                    $fields[$parts[0]][$parts[1]] = $value;
                }
            }
        }

        if (empty($fields)) {
            return [];
        }

        // Data can be a key-value pair, or an array where the key is a value,
        // and there may be multiple values.
        // So handle key-value field mapping.
        $fieldKey = $this->mapperConfig->getSectionSetting('params', 'fields.key');
        $fieldValue = $this->mapperConfig->getSectionSetting('params', 'fields.value');

        // Prepare the fields one time when there are fields and key/value.

        // Field key is in a subkey of a list of fields. Example content-dm:
        // [key => "title", label => "Title", value => "value"]
        if ($fieldKey) {
            $result = array_fill_keys(array_column($fields, $fieldKey), []);
            foreach ($fields as $fieldData) {
                if (isset($fieldData[$fieldKey], $fieldData[$fieldValue])) {
                    $key = $fieldData[$fieldKey];
                    $value = $fieldData[$fieldValue];
                    if (is_array($value)) {
                        $result[$key] = array_merge($result[$key], array_values($value));
                    } else {
                        $result[$key][] = $value;
                    }
                }
            }
            return $result;
        }

        // Key for value is an associative key.
        // [fieldValue => "value"]
        if ($fieldValue) {
            $result = [];
            foreach ($fields as $fieldData) {
                if (isset($fieldData[$fieldValue])) {
                    $value = $fieldData[$fieldValue];
                    if (is_array($value)) {
                        $result[$fieldValue] = array_merge($result[$fieldValue] ?? [], array_values($value));
                    } else {
                        $result[$fieldValue][] = $value;
                    }
                }
            }
            return $result;
        }

        return $fields;
    }

    /**
     * Get result from a xpath expression on a xml.
     *
     * If the xpath contains a function (like `substring(xpath, 2)`),
     * `evaluate()` is used and the output may be a simple string.
     * Else `query()` is used and the output is a node list, so it's possible to
     * do another query, included relative ones, on each node.
     *
     * Note: DOMXPath->query() and SimpleXML->xpath() don't work with xpath
     * functions like `substring(xpath, 2)`, that output a single scalar value,
     * not a list of nodes.
     *
     * @param DOMDocument|SimpleXMLElement $xml
     * @param string $query XPath expression.
     * @param DOMNode|null $contextNode Optional context node.
     * @return DOMNode[]|string[]
     */
    protected function xpathQuery($xml, string $query, ?DOMNode $contextNode = null): array
    {
        if ($xml instanceof SimpleXMLElement) {
            $dom = dom_import_simplexml($xml);
            $doc = new DOMDocument();
            $doc->appendChild($doc->importNode($dom, true));
            $xml = $doc;
        }

        $xpath = new DOMXPath($xml);

        // Register common rdf namespaces as fallbacks.
        // These are used by IdRef, BnF, and other authority providers.
        static $commonNamespaces = [
            'bio' => 'http://purl.org/vocab/bio/0.1/',
            'bnf-onto' => 'http://data.bnf.fr/ontology/bnf-onto/',
            'dbpedia-owl' => 'http://dbpedia.org/ontology/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'dcterms' => 'http://purl.org/dc/terms/',
            'ead' => 'urn:isbn:1-931666-22-9',
            'foaf' => 'http://xmlns.com/foaf/0.1/',
            'gml' => 'http://www.opengis.net/gml',
            'idref' => 'http://www.idref.fr/',
            'isni' => 'https://isni.org/ontology#',
            'lido' => 'http://www.lido-schema.org',
            'owl' => 'http://www.w3.org/2002/07/owl#',
            'rdaGr2' => 'http://rdvocab.info/ElementsGr2/',
            'rdaGr3' => 'http://rdvocab.info/ElementsGr3/',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
            'skos' => 'http://www.w3.org/2004/02/skos/core#',
            'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        ];
        foreach ($commonNamespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        // Auto-register namespaces from the document for XPath queries.
        // This allows queries like /foaf:Person/foaf:name to work.
        // Document namespaces override the common ones if different.
        foreach ($xpath->query('namespace::*', $xml->documentElement) ?: [] as $node) {
            $prefix = $node->localName;
            $uri = $node->nodeValue;
            // Skip the default 'xml' namespace and empty prefixes.
            if ($prefix && $prefix !== 'xml' && $uri) {
                $xpath->registerNamespace($prefix, $uri);
            }
        }

        $result = $xpath->evaluate($query, $contextNode);

        if ($result === false || $result === '') {
            return [];
        }

        if (is_array($result)) {
            return array_map('strval', $result);
        }

        if (!is_object($result)) {
            return [(string) $result];
        }

        /** @var DOMNodeList $result */
        $nodes = [];
        foreach ($result as $node) {
            $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * Recursive helper to flatten an array with separator ".".
     *
     * @example
     * ```
     * // The following recursive array:
     * [
     *     'video' => [
     *         'data.format' => 'jpg',
     *         'creator' => ['alpha', 'beta'],
     *     ],
     * ]
     * // is converted into:
     * [
     *     'video.data\.format' => 'jpg',
     *     'creator.0' => 'alpha',
     *     'creator.1' => 'beta',
     * ]
     * ```
     */
    protected function flatArrayRecursive(array &$array, array &$flatArray, ?string $prefix = null): void
    {
        foreach ($array as $key => $value) {
            $escapedKey = strtr((string) $key, ['.' => '\.', '\\' => '\\\\']);
            $fullKey = $prefix === null ? $escapedKey : $prefix . '.' . $escapedKey;

            if (is_array($value)) {
                $this->flatArrayRecursive($value, $flatArray, $fullKey);
            } else {
                $flatArray[$fullKey] = $value;
            }
        }
    }
}
