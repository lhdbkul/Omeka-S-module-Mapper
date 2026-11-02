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
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
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
     * Preprocessor reused as a postprocess engine for export. Holds an XSL
     * chain runner. Optional: when null, mappings cannot declare postprocess.
     *
     * @var \Mapper\Stdlib\Preprocessor|null
     */
    protected $preprocessor;

    /**
     * Optional Twig-XML engine for .twig.xml postprocess templates.
     *
     * @var \Mapper\Stdlib\TwigXmlEngine|null
     */
    protected $twigXmlEngine;

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
     * Inject the Preprocessor used to run the [info] postprocess chain after
     * serialization (XSL transformations on the produced DOM).
     */
    public function setPreprocessor(?Preprocessor $preprocessor): self
    {
        $this->preprocessor = $preprocessor;
        return $this;
    }

    /**
     * Inject the Twig-XML engine used to render .twig.xml postprocess templates
     * as an alternative to XSL.
     */
    public function setTwigXmlEngine(?TwigXmlEngine $engine): self
    {
        $this->twigXmlEngine = $engine;
        return $this;
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
     * Serialize a value through a map for export direction.
     *
     * Symmetric counterpart of convertString(): takes a value (typically from
     * an Omeka resource) and applies the map's mod (raw/val/pattern/filters/
     * prepend/append) to produce the target string.
     *
     * Accepts a string, a ValueRepresentation, or null. ValueRepresentation is
     * normalized via value() to honor the underlying datatype (literal text,
     * uri value, resource id label).
     *
     * @param string|ValueRepresentation|null $value Source value to serialize.
     * @param array $map Map definition (must contain 'mod' or be returned raw).
     * @return string The serialized result.
     */
    public function serializeValue($value, array $map = []): string
    {
        if ($value instanceof ValueRepresentation) {
            $value = (string) $value->value();
        }

        if ($value === null) {
            $value = '';
        } elseif (!is_scalar($value)) {
            return '';
        } else {
            $value = (string) $value;
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
     * Extract values from an Omeka resource using an XPath-like path.
     *
     * Supports a small subset of XPath geared at Omeka resources rather than
     * raw JSON-LD, so the path stays stable across JSON-LD evolutions.
     *
     * Supported syntax:
     * - `dcterms:title`                       all values of the property
     * - `dcterms:title[@language='fra']`      filter by language
     * - `dcterms:title[@datatype='literal']`  filter by datatype
     * - `o:id`, `o:title`, `o:is_public`      resource meta
     * - `o:resource_class/o:term`             chained meta
     * - `o:resource_template/o:label`         chained meta
     * - `o:owner/o:email`                     chained meta
     * - `o:item_set/dcterms:title`            traversal item → item sets
     * - `o:media[0]/o:original_url`           indexed traversal
     * - `o:media[*]/o:original_url`           wildcard traversal
     *
     * @return array List of scalar values (string|int|bool) ready for
     *   serializeValue(). ValueRepresentation are normalized via value().
     */
    public function extractFromResource(AbstractResourceRepresentation $resource, string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [];
        }

        $segments = $this->parseOmekaPath($path);
        if (!$segments) {
            return [];
        }

        $current = [$resource];
        foreach ($segments as $segment) {
            $current = $this->resolveOmekaSegment($current, $segment);
            if (!$current) {
                return [];
            }
        }

        $result = [];
        foreach ($current as $item) {
            $normalized = $this->normalizeOmekaValue($item);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    /**
     * Split an Omeka path on slashes, preserving bracketed predicates.
     *
     * `o:media[0]/o:original_url` → ['o:media[0]', 'o:original_url']
     */
    protected function parseOmekaPath(string $path): array
    {
        $segments = [];
        $buffer = '';
        $depth = 0;
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            $c = $path[$i];
            if ($c === '[') {
                $depth++;
                $buffer .= $c;
            } elseif ($c === ']') {
                $depth--;
                $buffer .= $c;
            } elseif ($c === '/' && $depth === 0) {
                if ($buffer !== '') {
                    $segments[] = $buffer;
                    $buffer = '';
                }
            } else {
                $buffer .= $c;
            }
        }
        if ($buffer !== '') {
            $segments[] = $buffer;
        }
        return $segments;
    }

    /**
     * Resolve one segment against a list of current targets.
     *
     * Returns the next list of targets (resources, ValueRepresentation,
     * scalars). Empty array stops the chain in extractFromResource().
     */
    protected function resolveOmekaSegment(array $targets, string $segment): array
    {
        // Extract predicate "[...]" if any.
        $predicate = null;
        $name = $segment;
        if (preg_match('~^(?<name>[^\[]+)\[(?<pred>[^\]]+)\]$~', $segment, $m)) {
            $name = $m['name'];
            $predicate = trim($m['pred']);
        }

        $next = [];
        foreach ($targets as $target) {
            $resolved = $this->resolveOmekaName($target, $name);
            if ($resolved === null || $resolved === [] || $resolved === '') {
                continue;
            }
            if (!is_array($resolved)) {
                $resolved = [$resolved];
            }
            if ($predicate !== null) {
                $resolved = $this->applyOmekaPredicate($resolved, $predicate);
            }
            foreach ($resolved as $r) {
                $next[] = $r;
            }
        }
        return $next;
    }

    /**
     * Resolve a name (without predicate) on a single target.
     *
     * @return mixed Single value, list of values, or null.
     */
    protected function resolveOmekaName($target, string $name)
    {
        if ($target instanceof AbstractResourceRepresentation) {
            return $this->resolveOmekaNameOnResource($target, $name);
        }
        if ($target instanceof ValueRepresentation) {
            // Allow dot-suffixes on a value: @value, @language, @datatype.
            switch ($name) {
                case '@value':
                    return (string) $target->value();
                case '@language':
                    return (string) $target->lang();
                case '@datatype':
                    return (string) $target->type();
            }
        }
        return null;
    }

    /**
     * Resolve a name on an Omeka resource representation.
     */
    protected function resolveOmekaNameOnResource(AbstractResourceRepresentation $resource, string $name)
    {
        // Property term (contains a colon and not a leading "o:" meta).
        if (strpos($name, ':') !== false && strpos($name, 'o:') !== 0) {
            if ($resource instanceof AbstractResourceEntityRepresentation) {
                return $resource->value($name, ['all' => true]) ?: [];
            }
            return [];
        }

        // Omeka meta accessors.
        switch ($name) {
            case 'o:id':
                return method_exists($resource, 'id') ? $resource->id() : null;
            case 'o:title':
                return method_exists($resource, 'title') ? $resource->title() : null;
            case 'o:is_public':
                return method_exists($resource, 'isPublic') ? $resource->isPublic() : null;
            case 'o:created':
                return method_exists($resource, 'created') ? $resource->created() : null;
            case 'o:modified':
                return method_exists($resource, 'modified') ? $resource->modified() : null;
            case 'o:resource_class':
                return method_exists($resource, 'resourceClass') ? $resource->resourceClass() : null;
            case 'o:resource_template':
                return method_exists($resource, 'resourceTemplate') ? $resource->resourceTemplate() : null;
            case 'o:owner':
                return method_exists($resource, 'owner') ? $resource->owner() : null;
            case 'o:item_set':
                return method_exists($resource, 'itemSets') ? $resource->itemSets() : null;
            case 'o:media':
                return method_exists($resource, 'media') ? $resource->media() : null;
            case 'o:item':
                return method_exists($resource, 'item') ? $resource->item() : null;
            case 'o:term':
                return method_exists($resource, 'term') ? $resource->term() : null;
            case 'o:label':
                return method_exists($resource, 'label') ? $resource->label() : null;
            case 'o:email':
                return method_exists($resource, 'email') ? $resource->email() : null;
            case 'o:name':
                return method_exists($resource, 'name') ? $resource->name() : null;
            case 'o:original_url':
                return method_exists($resource, 'originalUrl') ? $resource->originalUrl() : null;
            case 'o:filename':
                return method_exists($resource, 'filename') ? $resource->filename() : null;
            case 'o:source':
                return method_exists($resource, 'source') ? $resource->source() : null;
            case 'o:media_type':
                return method_exists($resource, 'mediaType') ? $resource->mediaType() : null;
            case 'o:site_url':
                return $this->siteUrls($resource);
        }
        return null;
    }

    /**
     * Get the absolute urls of a resource in each of its sites.
     *
     * The url depends on the site slug and on modules like Clean Url, so it
     * cannot be built with a pattern. A media uses the sites of its item.
     */
    protected function siteUrls(AbstractResourceRepresentation $resource): array
    {
        $siteResource = $resource instanceof \Omeka\Api\Representation\MediaRepresentation
            ? $resource->item()
            : $resource;
        if (!method_exists($siteResource, 'sites') || !method_exists($resource, 'siteUrl')) {
            return [];
        }
        $urls = [];
        foreach ($siteResource->sites() as $site) {
            $urls[] = $resource->siteUrl($site->slug(), true);
        }
        return $urls;
    }

    /**
     * Apply a predicate to a list of resolved values.
     *
     * Supported predicates:
     * - `0`, `N`           positional index (zero-based)
     * - `*`                wildcard (no-op, kept for clarity)
     * - `@language='fra'`  filter ValueRepresentation by language
     * - `@datatype='literal'` filter ValueRepresentation by type
     */
    protected function applyOmekaPredicate(array $values, string $predicate): array
    {
        if ($predicate === '*') {
            return array_values($values);
        }

        if (is_numeric($predicate)) {
            $index = (int) $predicate;
            $list = array_values($values);
            return isset($list[$index]) ? [$list[$index]] : [];
        }

        if (preg_match("~^@(?<key>language|datatype)\s*=\s*['\"](?<val>[^'\"]*)['\"]$~", $predicate, $m)) {
            $key = $m['key'];
            $expected = strtolower($m['val']);
            $filtered = [];
            foreach ($values as $v) {
                if (!$v instanceof ValueRepresentation) {
                    continue;
                }
                $actual = $key === 'language'
                    ? strtolower((string) $v->lang())
                    : strtolower((string) $v->type());
                if ($actual === $expected) {
                    $filtered[] = $v;
                }
            }
            return $filtered;
        }

        return $values;
    }

    /**
     * Normalize a leaf value to a scalar suitable for serializeValue().
     */
    protected function normalizeOmekaValue($item)
    {
        if ($item === null) {
            return null;
        }
        if (is_scalar($item)) {
            return $item;
        }
        if ($item instanceof ValueRepresentation) {
            $type = $item->type();
            if ($type === 'uri' || strpos($type, ':uri') !== false) {
                return (string) $item->uri() ?: (string) $item->value();
            }
            // A linked resource has no value, so use its title.
            $valueResource = $item->valueResource();
            return $valueResource
                ? (string) $valueResource->displayTitle()
                : (string) $item->value();
        }
        if ($item instanceof \DateTime) {
            return $item->format(\DateTime::ATOM);
        }
        if (is_object($item) && method_exists($item, '__toString')) {
            return (string) $item;
        }
        if ($item instanceof AbstractResourceRepresentation) {
            return method_exists($item, 'title') ? (string) $item->title() : (string) ($item->id() ?? '');
        }
        return null;
    }

    /**
     * Serialize an Omeka resource through the current mapping (export).
     *
     * Iterates over [maps] and, for each map, reads from the resource via
     * extractFromResource(), pipes the value through serializeValue(), and
     * accumulates the result in either a DOMDocument (for structured XML
     * targets) or a nested array (for JSON / array targets).
     *
     * Format detection:
     * - explicit: [params] target_type = xml | array
     * - implicit: if every to.field starts with `/`, output is XML; otherwise
     *   array (jsdot path).
     *
     * Namespaces for the XML output are read from [params] keys
     * `xmlns.<prefix> = <uri>`.
     *
     * @return DOMDocument|array|null DOMDocument for XML, array for JSON/PHP,
     *   null when no mapping is loaded or no map produced output.
     */
    public function serialize(AbstractResourceRepresentation $resource)
    {
        $mapping = $this->mapperConfig->getMapping($this->mappingName);
        if (!$mapping) {
            return null;
        }

        $maps = $mapping['maps'] ?? [];
        if (!$maps) {
            return null;
        }

        $result = $this->detectSerializationFormat($mapping) === 'xml'
            ? $this->serializeToDom($resource, $mapping)
            : $this->serializeToArray($resource, $mapping);

        return $this->applyPostprocess($result, $mapping);
    }

    /**
     * Apply the [info] postprocess chain to a serialized result.
     *
     * Each reference is dispatched by extension :
     * - .twig.xml → TwigXmlEngine (when injected)
     * - .xsl / other → Preprocessor (XSL chain)
     *
     * Only DOMDocument outputs are supported. Returns the original result
     * untouched when no postprocess is declared, when the required engine is
     * missing, or when any step yields an empty string.
     */
    protected function applyPostprocess($result, array $mapping)
    {
        $chain = $mapping['info']['postprocess'] ?? null;
        if (empty($chain) || !$result instanceof DOMDocument) {
            return $result;
        }

        $xml = $result->saveXML();
        if (!is_string($xml) || $xml === '') {
            return $result;
        }

        $context = $mapping['info']['context'] ?? null;

        foreach ((array) $chain as $reference) {
            $reference = trim((string) $reference);
            if ($reference === '') {
                continue;
            }
            $isTwig = (bool) preg_match('/\.twig\.xml$/i', $reference);
            $transformed = $isTwig
                ? $this->applyTwigXmlPostprocess($xml, $reference, $context)
                : $this->applyXslPostprocess($xml, $reference, $context);
            if (!is_string($transformed) || $transformed === '') {
                return $result;
            }
            $xml = $transformed;
        }

        $newDoc = new DOMDocument('1.0', 'UTF-8');
        $newDoc->preserveWhiteSpace = false;
        $newDoc->formatOutput = true;
        if (!@$newDoc->loadXML($xml)) {
            return $result;
        }
        return $newDoc;
    }

    /**
     * Run a single XSL postprocess step through the injected Preprocessor.
     */
    protected function applyXslPostprocess(string $xml, string $reference, ?string $context): ?string
    {
        if (!$this->preprocessor) {
            return null;
        }
        return $this->preprocessor->process($xml, [$reference], [], $context);
    }

    /**
     * Run a single Twig-XML postprocess step.
     *
     * Loads the template via module:/user:/absolute resolution, parses the
     * pivot XML into a context array, then renders.
     */
    protected function applyTwigXmlPostprocess(string $xml, string $reference, ?string $context): ?string
    {
        if (!$this->twigXmlEngine) {
            return null;
        }
        $template = $this->loadTwigXmlTemplate($reference, $context);
        if ($template === null) {
            return null;
        }
        $vars = $this->xmlPivotToContext($xml);
        return $this->twigXmlEngine->render($template, $vars);
    }

    /**
     * Resolve a Twig-XML template reference to its content.
     *
     * Supports the same prefixes as the Preprocessor: module:, user:, absolute
     * path, plain filename relative to the module mapping base or to the given
     * context folder.
     */
    protected function loadTwigXmlTemplate(string $reference, ?string $context): ?string
    {
        $basePath = $this->preprocessor ? $this->preprocessor->getBasePath() : null;
        $userBasePath = $this->preprocessor ? $this->preprocessor->getUserBasePath() : null;

        $candidates = [];
        if (strncmp($reference, 'module:', 7) === 0 && $basePath) {
            $candidates[] = $basePath . '/' . substr($reference, 7);
        } elseif (strncmp($reference, 'user:', 5) === 0 && $userBasePath) {
            $candidates[] = $userBasePath . '/' . substr($reference, 5);
        } elseif (strpos($reference, '/') === 0) {
            $candidates[] = $reference;
        } else {
            if ($basePath) {
                if ($context) {
                    $candidates[] = $basePath . '/' . $context . '/' . $reference;
                }
                $candidates[] = $basePath . '/common/' . $reference;
                $candidates[] = $basePath . '/' . $reference;
            }
        }
        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $content = @file_get_contents($path);
                return $content === false ? null : $content;
            }
        }
        return null;
    }

    /**
     * Convert a pivot XML string into a nested associative array suitable as
     * Twig-XML rendering context.
     *
     * Children are keyed by `prefix_localName` (or just `localName` when
     * unprefixed), and always wrapped in a numerically indexed array so
     * templates can iterate uniformly via `{% for x in dc_title %}`.
     */
    protected function xmlPivotToContext(string $xml): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return [];
        }
        if (!$doc->documentElement) {
            return [];
        }
        return $this->domElementToContext($doc->documentElement);
    }

    protected function domElementToContext(\DOMElement $element): array
    {
        $result = [];
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $key = $child->prefix
                ? $child->prefix . '_' . $child->localName
                : $child->localName;
            $value = $this->domElementHasChildElements($child)
                ? $this->domElementToContext($child)
                : $child->textContent;
            if (!isset($result[$key])) {
                $result[$key] = [];
            }
            $result[$key][] = $value;
        }
        return $result;
    }

    protected function domElementHasChildElements(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return true;
            }
        }
        return false;
    }

    /**
     * Decide whether the mapping targets XML (DOMDocument) or array (jsdot).
     */
    protected function detectSerializationFormat(array $mapping): string
    {
        $params = $mapping['params'] ?? [];
        if (!empty($params['target_type'])) {
            $explicit = strtolower((string) $params['target_type']);
            if (in_array($explicit, ['xml', 'array'], true)) {
                return $explicit;
            }
        }
        foreach ($mapping['maps'] ?? [] as $map) {
            $field = $map['to']['field'] ?? '';
            if ($field !== '') {
                return ($field[0] === '/') ? 'xml' : 'array';
            }
        }
        return 'array';
    }

    /**
     * Build a DOM tree by accumulating to.field XPath-like paths.
     *
     * Each map's to.field is split on '/'. The last segment is created once per
     * value (siblings allowed); preceding segments are containers reused across
     * maps when their absolute path matches.
     */
    protected function serializeToDom(AbstractResourceRepresentation $resource, array $mapping): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $namespaces = $this->extractMappingNamespaces($mapping);
        $containers = [];

        foreach ($mapping['maps'] ?? [] as $map) {
            $field = $map['to']['field'] ?? '';
            if ($field === '' || $field[0] !== '/') {
                continue;
            }

            $values = $this->resolveMapValuesForExport($resource, $map);
            if (!$values) {
                continue;
            }

            $segments = array_values(array_filter(explode('/', trim($field, '/'))));
            if (!$segments) {
                continue;
            }
            $leaf = array_pop($segments);

            $parent = $doc;
            $partial = '';
            foreach ($segments as $seg) {
                $partial .= '/' . $seg;
                if (isset($containers[$partial])) {
                    $parent = $containers[$partial];
                    continue;
                }
                $newEl = $this->createNamespacedElement($doc, $seg, $namespaces);
                $parent->appendChild($newEl);
                $containers[$partial] = $newEl;
                $parent = $newEl;
            }

            foreach ($values as $value) {
                $leafEl = $this->createNamespacedElement($doc, $leaf, $namespaces);
                $leafEl->appendChild($doc->createTextNode($value));
                $parent->appendChild($leafEl);
            }
        }

        $this->declareNamespacesOnRoot($doc, $namespaces);

        return $doc;
    }

    /**
     * Hoist xmlns declarations to the root so children don't repeat them.
     */
    protected function declareNamespacesOnRoot(DOMDocument $doc, array $namespaces): void
    {
        $root = $doc->documentElement;
        if (!$root) {
            return;
        }
        foreach ($namespaces as $prefix => $uri) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $uri);
        }
    }

    /**
     * Build a nested array by accumulating to.field jsdot paths.
     */
    protected function serializeToArray(AbstractResourceRepresentation $resource, array $mapping): array
    {
        $result = [];

        foreach ($mapping['maps'] ?? [] as $map) {
            $field = $map['to']['field'] ?? '';
            if ($field === '' || $field[0] === '/') {
                continue;
            }

            $values = $this->resolveMapValuesForExport($resource, $map);
            if (!$values) {
                continue;
            }

            $this->arrayPathSet($result, $field, $values);
        }

        return $result;
    }

    /**
     * Extract source values from an Omeka resource and pipe each through
     * serializeValue(). Default maps (no from.path) honor mod.raw / mod.val.
     *
     * @return string[] List of non-empty serialized strings.
     */
    protected function resolveMapValuesForExport(AbstractResourceRepresentation $resource, array $map): array
    {
        $fromPath = $map['from']['path'] ?? null;
        $mod = $map['mod'] ?? [];

        if (!$fromPath) {
            $raw = $mod['raw'] ?? $mod['val'] ?? null;
            if ($raw === null || $raw === '') {
                return [];
            }
            return [$this->serializeValue((string) $raw, $map)];
        }

        $sourceValues = $this->extractFromResource($resource, $fromPath);
        if (!$sourceValues) {
            return [];
        }

        $serialized = [];
        foreach ($sourceValues as $value) {
            $s = $this->serializeValue($value, $map);
            if ($s !== '') {
                $serialized[] = $s;
            }
        }
        return $serialized;
    }

    /**
     * Read XML namespace declarations from [params] (key prefix `xmlns.`).
     */
    protected function extractMappingNamespaces(array $mapping): array
    {
        $params = $mapping['params'] ?? [];
        $namespaces = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if (strncmp($key, 'xmlns.', 6) === 0 && is_scalar($value)) {
                $namespaces[substr($key, 6)] = (string) $value;
            }
        }
        return $namespaces;
    }

    /**
     * Create an element honoring the namespace prefix when known.
     */
    protected function createNamespacedElement(DOMDocument $doc, string $qname, array $namespaces): \DOMElement
    {
        $colon = strpos($qname, ':');
        if ($colon !== false) {
            $prefix = substr($qname, 0, $colon);
            if (isset($namespaces[$prefix])) {
                return $doc->createElementNS($namespaces[$prefix], $qname);
            }
        }
        return $doc->createElement($qname);
    }

    /**
     * Set or merge values at a jsdot path inside a nested array.
     */
    protected function arrayPathSet(array &$arr, string $path, array $values): void
    {
        $segments = array_values(array_filter(explode('.', $path), 'strlen'));
        if (!$segments) {
            return;
        }

        $cursor = &$arr;
        $lastIndex = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            if ($i === $lastIndex) {
                if (!isset($cursor[$seg])) {
                    $cursor[$seg] = count($values) === 1 ? $values[0] : array_values($values);
                } elseif (is_array($cursor[$seg])) {
                    $cursor[$seg] = array_merge(array_values($cursor[$seg]), array_values($values));
                } else {
                    $cursor[$seg] = array_merge([$cursor[$seg]], array_values($values));
                }
                return;
            }
            if (!isset($cursor[$seg]) || !is_array($cursor[$seg])) {
                $cursor[$seg] = [];
            }
            $cursor = &$cursor[$seg];
        }
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
        // If so, skip this value entirely. This only applies to single-brace
        // {path} data lookups: a pattern made only of {{ variable }} (with no
        // data path) must not be skipped, since the variables are resolved
        // above.
        if (!empty($mod['replace'])) {
            $hasDataReplacement = false;
            $allEmpty = true;
            foreach ($mod['replace'] as $wrappedQuery) {
                if (mb_substr($wrappedQuery, 0, 2) === '{{') {
                    continue; // Skip context variables.
                }
                $hasDataReplacement = true;
                if (isset($replace[$wrappedQuery]) && strlen($replace[$wrappedQuery])) {
                    $allEmpty = false;
                    break;
                }
            }
            if ($hasDataReplacement && $allEmpty) {
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
        // If so, skip this value entirely. This only applies to single-brace
        // {path} data lookups: a pattern made only of {{ variable }} (with no
        // data path) must not be skipped, since the variables are resolved
        // above.
        if (!empty($mod['replace'])) {
            $hasDataReplacement = false;
            $allEmpty = true;
            foreach ($mod['replace'] as $wrappedQuery) {
                if (mb_substr($wrappedQuery, 0, 2) === '{{') {
                    continue; // Skip context variables.
                }
                $hasDataReplacement = true;
                if (isset($replace[$wrappedQuery]) && strlen($replace[$wrappedQuery])) {
                    $allEmpty = false;
                    break;
                }
            }
            if ($hasDataReplacement && $allEmpty) {
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

        // Register common namespaces as fallbacks for XPath queries, so an
        // import mapping matches real notices whatever prefix the source
        // document uses, even a default namespace. A namespace declared in the
        // document still overrides the matching fallback below.
        static $commonNamespaces = [
            // Authority providers (IdRef, BnF, dbpedia…) and core RDF.
            'bio' => 'http://purl.org/vocab/bio/0.1/',
            'bnf-onto' => 'http://data.bnf.fr/ontology/bnf-onto/',
            'dbpedia-owl' => 'http://dbpedia.org/ontology/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'dcterms' => 'http://purl.org/dc/terms/',
            'foaf' => 'http://xmlns.com/foaf/0.1/',
            'gml' => 'http://www.opengis.net/gml',
            'idref' => 'http://www.idref.fr/',
            'isni' => 'https://isni.org/ontology#',
            'owl' => 'http://www.w3.org/2002/07/owl#',
            'rdaGr2' => 'http://rdvocab.info/ElementsGr2/',
            'rdaGr3' => 'http://rdvocab.info/ElementsGr3/',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
            'skos' => 'http://www.w3.org/2004/02/skos/core#',
            'xsd' => 'http://www.w3.org/2001/XMLSchema#',
            // XML metadata exchange formats used as import sources.
            'alto' => 'http://www.loc.gov/standards/alto/ns-v4#',
            'dcmitype' => 'http://purl.org/dc/dcmitype/',
            'ead' => 'urn:isbn:1-931666-22-9',
            'lido' => 'http://www.lido-schema.org',
            'marc' => 'http://www.loc.gov/MARC21/slim',
            'marcxchange' => 'info:lc/xmlns/marcxchange-v2',
            'mets' => 'http://www.loc.gov/METS/',
            'mods' => 'http://www.loc.gov/mods/v3',
            'oai' => 'http://www.openarchives.org/OAI/2.0/',
            'oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
            'premis' => 'http://www.loc.gov/premis/v3',
            'srw' => 'http://www.loc.gov/zing/srw/',
            'tei' => 'http://www.tei-c.org/ns/1.0',
            'xlink' => 'http://www.w3.org/1999/xlink',
            'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            // Linked-data vocabularies for digital-object / surrogate links,
            // mirroring the formats recommended by the DigitalObject module.
            'bf' => 'http://id.loc.gov/ontologies/bibframe/',
            'bibo' => 'http://purl.org/ontology/bibo/',
            'crm' => 'http://www.cidoc-crm.org/cidoc-crm/',
            'edm' => 'http://www.europeana.eu/schemas/edm/',
            'pcdm' => 'http://pcdm.org/models#',
            'premis-rdf' => 'http://www.loc.gov/premis/rdf/v3#',
            'rdam' => 'http://rdaregistry.info/Elements/m/',
            'rdaw' => 'http://rdaregistry.info/Elements/w/',
            'rico' => 'https://www.ica.org/standards/RiC/ontology#',
            'schema' => 'http://schema.org/',
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
