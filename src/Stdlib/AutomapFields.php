<?php declare(strict_types=1);

/**
 * AutomapFields - Automap field specifications to normalized property terms.
 *
 * This class provides intelligent field resolution, converting various input
 * formats (labels, local names, terms) into canonical property terms with
 * full qualifiers (datatype, language, visibility, pattern).
 *
 * Features:
 * - Property term resolution (e.g., "dcterms:title")
 * - Local name matching (e.g., "title" → "dcterms:title")
 * - Label matching (e.g., "Dublin Core : Title" → "dcterms:title")
 * - Datatype normalization (e.g., "item" → "resource:item")
 * - Custom vocab label resolution
 *   (e.g., ^^customvocab:"My List" → ^^customvocab:123)
 * - Old pattern detection with warnings
 * - Multiple targets with | separator
 *
 * Uses MapNormalizer::parseFieldSpec() for field parsing, which handles
 * quoted custom vocab labels with spaces (common in spreadsheet headers).
 *
 * Migrated from BulkImport\Mvc\Controller\Plugin\AutomapFields.
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

use Common\Stdlib\EasyMeta;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Log\Logger;
use Omeka\Api\Manager as ApiManager;

class AutomapFields
{
    /**
     * Pattern to detect old format (deprecated).
     */
    public const OLD_PATTERN_CHECK = '#'
        . '(?<prefix_with_space>(?:\^\^|@|§)\s)'
        . '|(?<datatypes_semicolon>\^\^\s*[a-zA-Z][^\^@§~\n\r;]*;)'
        . '|(?<unwrapped_customvocab_label>(?:\^\^|;)\s*customvocab:[^\d"\';\^\n]+)'
        . '#u';

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var EasyMeta
     */
    protected $easyMeta;

    /**
     * @var MapNormalizer
     */
    protected $mapNormalizer;

    /**
     * @var TranslatorInterface|null
     */
    protected $translator;

    /**
     * @var Logger|null
     */
    protected $logger;

    /**
     * @var array Cached property lists for term resolution.
     */
    protected $propertyLists;

    /**
     * @var array User-defined mapping overrides.
     */
    protected $map = [];

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        MapNormalizer $mapNormalizer,
        ?TranslatorInterface $translator = null,
        ?Logger $logger = null,
        array $map = []
    ) {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->mapNormalizer = $mapNormalizer;
        $this->translator = $translator;
        $this->logger = $logger;
        $this->map = $map;
    }

    /**
     * Automap a list of field specifications to normalized property data.
     *
     * @param array $fields List of field specifications.
     * @param array $options Options:
     *   - map: Additional mapping overrides.
     *   - check_field: Validate that field exists (default: true).
     *   - check_names_alone: Match local names without prefix (default: true).
     *   - single_target: Disable | separator (default: false).
     *   - output_full_matches: Return full data (default: false).
     *   - output_property_id: Include property_id in results (default: false).
     * @return array Mapped fields. With output_full_matches: field, datatype,
     *   language, is_public, pattern, property_id.
     */
    public function __invoke(array $fields, array $options = []): array
    {
        $options += [
            'map' => [],
            'check_field' => true,
            'check_names_alone' => true,
            'single_target' => false,
            'output_full_matches' => false,
            'output_property_id' => false,
        ];

        if (!$options['check_field']) {
            return $this->automapNoCheckField($fields, $options);
        }

        $automaps = array_fill_keys(array_keys($fields), null);
        $fields = $this->cleanStrings($fields);

        $checkNamesAlone = (bool) $options['check_names_alone'];
        $singleTarget = (bool) $options['single_target'];
        $outputFullMatches = (bool) $options['output_full_matches'];
        $outputPropertyId = $outputFullMatches && $options['output_property_id'];

        $map = array_merge($this->map, $options['map']);

        $lists = $this->preparePropertyLists($checkNamesAlone);
        $automapLists = $this->prepareAutomapLists($map);

        foreach ($fields as $index => $fieldsMulti) {
            // Split by | unless single_target or pattern present.
            $fieldsMulti = $singleTarget || strpos($fieldsMulti, '~') !== false
                ? [trim($fieldsMulti)]
                : array_filter(array_map('trim', explode('|', $fieldsMulti)));

            foreach ($fieldsMulti as $field) {
                $this->checkOldPattern($field);

                // Extract field part before qualifiers (^^, @, §, ~).
                // Handles labels with spaces like "Dublin Core:Title ^^literal".
                $fieldPart = $this->extractFieldPart($field);
                $lowerFieldPart = mb_strtolower($fieldPart);

                // Check custom automap list first.
                $found = $this->findInLists($fieldPart, $lowerFieldPart, $automapLists);
                if ($found !== null) {
                    $resolvedField = $map[$found] ?? $found;
                    $parsed = $this->parseSpec($field);
                    $automaps[$index][] = $outputFullMatches
                        ? $this->buildResult($resolvedField, $parsed, $outputPropertyId)
                        : $resolvedField;
                    continue;
                }

                // Check property lists (terms and labels).
                $found = $this->findInLists($fieldPart, $lowerFieldPart, $lists);
                if ($found !== null) {
                    $resolvedField = $this->propertyLists['names'][$found] ?? $found;
                    $parsed = $this->parseSpec($field);
                    $automaps[$index][] = $outputFullMatches
                        ? $this->buildResult($resolvedField, $parsed, $outputPropertyId)
                        : $resolvedField;
                }
            }
        }

        return $automaps;
    }

    /**
     * Automap without validating that fields exist.
     */
    protected function automapNoCheckField(array $fields, array $options): array
    {
        $automaps = array_fill_keys(array_keys($fields), null);
        $fields = $this->cleanStrings($fields);

        $singleTarget = (bool) $options['single_target'];
        $outputFullMatches = (bool) $options['output_full_matches'];
        $outputPropertyId = $outputFullMatches && $options['output_property_id'];

        foreach ($fields as $index => $fieldsMulti) {
            $fieldsMulti = $singleTarget || strpos($fieldsMulti, '~') !== false
                ? [$fieldsMulti]
                : array_filter(array_map('trim', explode('|', $fieldsMulti)));

            foreach ($fieldsMulti as $field) {
                $this->checkOldPattern($field);

                $parsed = $this->parseSpec($field);
                $fieldName = $parsed['field'] ?? '';

                if ($outputFullMatches) {
                    $automaps[$index][] = $this->buildResult($fieldName, $parsed, $outputPropertyId);
                } else {
                    $automaps[$index][] = $fieldName;
                }
            }
        }

        return $automaps;
    }

    /**
     * Parse a field specification string.
     *
     * Extracts the pattern part and delegates field parsing to MapNormalizer.
     *
     * @return array With keys: field, datatype, language, is_public, pattern.
     */
    protected function parseSpec(string $spec): array
    {
        $pattern = null;

        // Extract pattern part (everything after ~).
        $tildePos = mb_strpos($spec, '~');
        if ($tildePos !== false) {
            $pattern = trim(mb_substr($spec, $tildePos + 1));
            $spec = trim(mb_substr($spec, 0, $tildePos));
        }

        // Use MapNormalizer for field parsing.
        $parsed = $this->mapNormalizer->parseFieldSpec($spec);

        return [
            'field' => $parsed['field'],
            'datatype' => $parsed['datatype'] ?? [],
            'language' => $parsed['language'],
            'is_public' => $parsed['is_public'],
            'pattern' => $pattern,
        ];
    }

    /**
     * Build a full result array with all qualifiers.
     */
    protected function buildResult(
        string $field,
        array $parsed,
        bool $includePropertyId
    ): array {
        // Convert boolean visibility to string for API compatibility.
        $isPublic = $parsed['is_public'] ?? null;
        if ($isPublic === false) {
            $isPublic = 'private';
        } elseif ($isPublic === true) {
            $isPublic = 'public';
        }

        $result = [
            'field' => $field ?: null,
            'datatype' => $parsed['datatype'] ?? [],
            'language' => $parsed['language'] ?? null,
            'is_public' => $isPublic,
            'pattern' => $parsed['pattern'] ?? null,
        ];

        if ($includePropertyId) {
            $result['property_id'] = $field
                ? $this->easyMeta->propertyId($field)
                : null;
        }

        return $this->processPattern($result);
    }

    /**
     * Process pattern to extract raw, replace, and filter components.
     */
    protected function processPattern(array $result): array
    {
        if (empty($result['pattern'])) {
            return $result;
        }

        $pattern = $result['pattern'];

        // Check for quoted raw value.
        $first = mb_substr($pattern, 0, 1);
        $last = mb_substr($pattern, -1);
        if (($first === '"' && $last === '"')
            || ($first === "'" && $last === "'")
        ) {
            $result['raw'] = trim(mb_substr($pattern, 1, -1));
            $result['pattern'] = null;
            return $result;
        }

        // Special pattern: simple variable replacement without filter.
        if ($pattern === '{{ value }}') {
            $result['replace'][] = $pattern;
            return $result;
        }

        // Extract replacements ({{ path }}).
        if (preg_match_all('~\{\{( value |\S+?|\S.*?\S)\}\}~', $pattern, $matches) !== false) {
            $result['replace'] = empty($matches[0])
                ? []
                : array_values(array_unique($matches[0]));
        }

        // Extract filter expressions ({{ ...|filter }}).
        if (preg_match_all('~\{\{ ([^{}]+) \}\}~', $pattern, $matches) !== false) {
            $result['filters'] = empty($matches[0]) ? [] : array_unique($matches[0]);
            $result['filters'] = array_values(array_diff($result['filters'], ['{{ value }}']));
        }

        return $result;
    }

    /**
     * Prepare property lookup lists.
     */
    protected function preparePropertyLists(bool $checkNamesAlone): array
    {
        if ($this->propertyLists === null) {
            $this->loadPropertyLists();
        }

        $lists = [];

        // Term names (dcterms:title).
        $lists['names'] = array_combine(
            array_keys($this->propertyLists['names']),
            array_keys($this->propertyLists['names'])
        );
        $lists['lower_names'] = array_map('mb_strtolower', $lists['names']);

        // Labels (Dublin Core : Title).
        $labelNames = array_keys($this->propertyLists['names']);
        $labelLabels = \SplFixedArray::fromArray(
            array_keys($this->propertyLists['labels'])
        );
        $labelLabels->setSize(count($labelNames));
        $lists['labels'] = array_combine(
            $labelNames,
            array_map('strval', $labelLabels->toArray())
        );
        $lists['lower_labels'] = array_filter(
            array_map('mb_strtolower', $lists['labels'])
        );

        // Local names (title without prefix).
        if ($checkNamesAlone) {
            $lists['local_names'] = array_map(function ($v) {
                $w = explode(':', (string) $v);
                return end($w);
            }, $lists['names']);
            $lists['lower_local_names'] = array_map(
                'mb_strtolower',
                $lists['local_names']
            );

            $lists['local_labels'] = array_map(function ($v) {
                $w = explode(':', (string) $v);
                return end($w);
            }, $lists['labels']);
            $lists['lower_local_labels'] = array_map(
                'mb_strtolower',
                $lists['local_labels']
            );
        }

        return $lists;
    }

    /**
     * Load property term names and labels.
     */
    protected function loadPropertyLists(): void
    {
        $this->propertyLists = ['names' => [], 'labels' => []];

        $vocabularies = $this->api->search('vocabularies')->getContent();

        foreach ($vocabularies as $vocabulary) {
            $properties = $vocabulary->properties();
            if (empty($properties)) {
                continue;
            }

            foreach ($properties as $property) {
                $term = $property->term();
                $this->propertyLists['names'][$term] = $term;

                $label = $vocabulary->label() . ':' . $property->label();
                if (isset($this->propertyLists['labels'][$label])) {
                    $label .= ' (#' . $property->id() . ')';
                }
                $this->propertyLists['labels'][$label] = $term;
            }
        }

        // Add "dc:" prefix for "dcterms:" (common shorthand).
        if (isset($vocabularies[0])) {
            $dcVocab = $vocabularies[0];
            foreach ($dcVocab->properties() as $property) {
                $term = $property->term();
                $dcTerm = 'dc:' . substr($term, 8);
                $this->propertyLists['names'][$dcTerm] = $term;
            }
        }
    }

    /**
     * Prepare automap lists from user-defined map.
     *
     * Includes translations if translator is available.
     */
    protected function prepareAutomapLists(array $map): array
    {
        if (empty($map)) {
            return [];
        }

        // Add translations for each entry (case-sensitive and insensitive).
        if ($this->translator) {
            $additions = [];
            foreach ($map as $name => $norm) {
                // Translate original name.
                $translation = $this->translator->translate($name);
                if ($translation !== $name) {
                    $additions[$translation] = $norm;
                }
                // Translate lowercase name.
                $lowerName = mb_strtolower($name);
                $translationLower = $this->translator->translate($lowerName);
                if ($translationLower !== $lowerName) {
                    $additions[$translationLower] = $norm;
                }
            }
            $map = array_merge($map, $additions);
        }

        $map += array_combine($map, $map);

        $lists = [];
        $lists['base'] = array_combine(array_keys($map), array_keys($map));
        $lists['lower_base'] = array_map('mb_strtolower', $lists['base']);

        if ($lists['base'] === $lists['lower_base']) {
            unset($lists['base']);
        }

        return $lists;
    }

    /**
     * Find a field in lookup lists.
     */
    protected function findInLists(
        string $field,
        string $lowerField,
        array $lists
    ): ?string {
        foreach ($lists as $listName => $list) {
            $toSearch = strpos($listName, 'lower_') === 0 ? $lowerField : $field;
            $found = array_search($toSearch, $list, true);
            if ($found !== false) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Extract field part before qualifiers.
     *
     * Captures everything before the first qualifier marker (^^, @, §, ~).
     * Handles labels with spaces like "Dublin Core:Title ^^literal @en".
     */
    protected function extractFieldPart(string $spec): string
    {
        // Match everything until the first qualifier marker.
        // Similar to BulkImport's regex: (?<field>[^@§^~|\n\r]+)
        if (preg_match('/^([^@§\^~|]+)/u', $spec, $matches)) {
            return trim($matches[1]);
        }
        return trim($spec);
    }

    /**
     * Check for and warn about old pattern format.
     */
    protected function checkOldPattern(?string $field): bool
    {
        if (!$field || !preg_match(self::OLD_PATTERN_CHECK, $field)) {
            return false;
        }

        if ($this->logger) {
            $this->logger->warn(
                'The field pattern "{field}" uses old format. Update by '
                . 'replacing ";" with "^^", removing spaces after "^^", "@", '
                . '"§", and wrapping custom vocab labels with quotes.',
                ['field' => $field]
            );
        }

        return true;
    }

    /**
     * Clean whitespace and normalize colons.
     */
    protected function cleanStrings(array $strings): array
    {
        return array_map(function ($string) {
            $string = preg_replace(
                '/\s+/u',
                ' ',
                (string) $string
            );
            return preg_replace('~\s*:\s*~', ':', trim($string));
        }, $strings);
    }
}
