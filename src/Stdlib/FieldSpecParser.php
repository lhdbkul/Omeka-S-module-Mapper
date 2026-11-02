<?php declare(strict_types=1);

namespace Mapper\Stdlib;

use Common\Stdlib\EasyMeta;
use Omeka\Api\Manager as ApiManager;

/**
 * Parse the destination of a map: "dcterms:title ^^datatype @language §private".
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class FieldSpecParser
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var EasyMeta
     */
    protected $easyMeta;

    /**
     * Cached custom vocab labels to IDs.
     */
    protected ?array $customVocabLabels = null;

    public function __construct(ApiManager $api, EasyMeta $easyMeta)
    {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
    }

    /**
     * Parse a field specification string.
     *
     * Format: "dcterms:title ^^datatype @language §visibility"
     *
     * Handles quoted custom vocab labels with spaces:
     * "dcterms:type ^^customvocab:"My Type List" @fra"
     */
    public function parse(string $spec): array
    {
        $result = [
            'field' => null,
            'property_id' => null,
            'datatype' => null,
            'language' => null,
            'is_public' => null,
        ];

        $spec = trim($spec);
        if ($spec === '') {
            return $result;
        }

        // Remove pattern part if present.
        $tildePos = mb_strpos($spec, '~');
        if ($tildePos !== false) {
            $spec = trim(mb_substr($spec, 0, $tildePos));
        }

        // Split by whitespace, preserving quoted strings.
        preg_match_all('/(?:"[^"]*"|\'[^\']*\'|\S)+/', $spec, $matches);
        $parts = $matches[0];
        $datatypes = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $prefix = mb_substr($part, 0, 2);
            $prefixSingle = mb_substr($part, 0, 1);

            if ($prefix === '^^') {
                // Datatype.
                $datatypes[] = mb_substr($part, 2);
            } elseif ($prefixSingle === '@') {
                // Language.
                $result['language'] = mb_substr($part, 1);
            } elseif ($prefixSingle === '§') {
                // Visibility.
                $visibility = mb_strtolower(mb_substr($part, 1));
                $result['is_public'] = ($visibility !== 'private');
            } elseif ($result['field'] === null) {
                // First non-prefixed part is the field.
                $result['field'] = $part;
            }
        }

        if (!empty($datatypes)) {
            $result['datatype'] = $this->normalizeDatatypes($datatypes);
        }

        // Resolve property ID.
        if ($result['field']) {
            $propertyId = $this->easyMeta->propertyId($result['field']);
            if ($propertyId) {
                $result['property_id'] = $propertyId;
            }
        }

        return $result;
    }

    /**
     * Normalize datatypes, resolving custom vocab labels to IDs.
     *
     * @param array $datatypes List of datatype strings.
     * @return array Normalized datatypes.
     */
    public function normalizeDatatypes(array $datatypes): array
    {
        $result = [];
        foreach ($datatypes as $datatype) {
            if (strpos($datatype, 'customvocab:') === 0) {
                $datatype = $this->resolveCustomVocabDatatype($datatype);
            }
            $normalized = $this->easyMeta->dataTypeName($datatype);
            if ($normalized !== null) {
                $result[] = $normalized;
            } else {
                $result[] = $datatype;
            }
        }
        return array_values(array_unique(array_filter($result)));
    }

    /**
     * Resolve custom vocab datatype with label to ID.
     *
     * Converts "customvocab:'My List'" or 'customvocab:"My List"' to
     * "customvocab:123".
     */
    protected function resolveCustomVocabDatatype(string $datatype): string
    {
        $suffix = substr($datatype, 12);

        // Already an ID.
        if (is_numeric($suffix)) {
            return $datatype;
        }

        // Extract label from quotes.
        $first = mb_substr($suffix, 0, 1);
        $last = mb_substr($suffix, -1);
        if (($first === '"' && $last === '"')
            || ($first === "'" && $last === "'")
        ) {
            $label = mb_substr($suffix, 1, -1);
        } else {
            $label = $suffix;
        }

        // Lookup custom vocab ID by label.
        if ($this->customVocabLabels === null) {
            $this->loadCustomVocabLabels();
        }

        $id = $this->customVocabLabels[$label] ?? null;
        if ($id !== null) {
            return 'customvocab:' . $id;
        }

        return $datatype;
    }

    /**
     * Load custom vocab labels to IDs mapping.
     */
    protected function loadCustomVocabLabels(): void
    {
        $this->customVocabLabels = [];

        try {
            $customVocabs = $this->api
                ->search('custom_vocabs', [], ['responseContent' => 'resource'])
                ->getContent();
            foreach ($customVocabs as $customVocab) {
                $this->customVocabLabels[$customVocab->getLabel()] = $customVocab->getId();
            }
        } catch (\Throwable $e) {
            // Custom vocab module not installed.
        }
    }
}
