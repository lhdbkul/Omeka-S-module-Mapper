<?php declare(strict_types=1);

/**
 * Mapper filter trait for value transformations.
 *
 * Provides filter expressions using {{ value|filter }} syntax, inspired by
 * template languages like Twig, Jinja2, Django templates, Nunjucks, and
 * Liquid.
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

trait FilterTrait
{
    /**
     * Pattern for variables in filter expressions.
     *
     * @var string
     */
    protected $patternVars = '';

    /**
     * Variables available for filter expressions.
     *
     * @var array
     */
    protected $filterVars = [];

    /**
     * Filter metadata for export and reversibility analysis.
     *
     * Each entry describes how a filter behaves under inversion:
     * - reversible: the filter has an inverse that recovers the original value
     *   exactly (modulo unknown table keys). For lossy filters (upper, trim,
     *   striptags, slice, length, first, last) this is false.
     * - inverse: the conventional inverse filter name when one exists, even
     *   if the round-trip is lossy. Allows tooling to suggest the symmetric
     *   filter for export mappings (lower → upper, dateRevert → dateRevert).
     * - category: domain grouping for documentation and UI.
     *
     * It may be used by tooling to check that an export mapping does not rely
     * on lossy filters.
     */
    public static function filterMetadata(): array
    {
        return [
            // String case (lossy: original case lost).
            'upper'      => ['reversible' => false, 'inverse' => 'lower',  'category' => 'string'],
            'lower'      => ['reversible' => false, 'inverse' => 'upper',  'category' => 'string'],
            'capitalize' => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'title'      => ['reversible' => false, 'inverse' => null,     'category' => 'string'],

            // String trimming and slicing (lossy: discarded chars lost).
            'trim'       => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'striptags'  => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'slice'      => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'first'      => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'last'       => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'length'     => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'replace'    => ['reversible' => false, 'inverse' => 'replace','category' => 'string'],
            'basename'   => ['reversible' => false, 'inverse' => null,     'category' => 'path'],
            'format'     => ['reversible' => false, 'inverse' => null,     'category' => 'string'],
            'split'      => ['reversible' => false, 'inverse' => 'join',   'category' => 'array'],
            'join'       => ['reversible' => false, 'inverse' => 'split',  'category' => 'array'],
            'implode'    => ['reversible' => false, 'inverse' => 'split',  'category' => 'array'],
            'implodev'   => ['reversible' => false, 'inverse' => null,     'category' => 'array'],

            // Numeric (lossy: sign and units lost).
            'abs'        => ['reversible' => false, 'inverse' => null,     'category' => 'numeric'],

            // Encoding (reversible with proper inverse, not yet implemented).
            'e'          => ['reversible' => true,  'inverse' => null,     'category' => 'encoding'],
            'escape'     => ['reversible' => true,  'inverse' => null,     'category' => 'encoding'],
            'url_encode' => ['reversible' => true,  'inverse' => null,     'category' => 'encoding'],

            // Translation (lossy: original key lost).
            'translate'  => ['reversible' => false, 'inverse' => null,     'category' => 'i18n'],

            // Tables (reversible iff the table is bijective).
            'table'      => ['reversible' => true,  'inverse' => 'table_reverse', 'category' => 'lookup'],
            'table_reverse' => ['reversible' => true, 'inverse' => 'table', 'category' => 'lookup'],

            // Dates (involutive on strict format).
            'date'       => ['reversible' => false, 'inverse' => null,     'category' => 'date'],
            'dateIso'    => ['reversible' => false, 'inverse' => null,     'category' => 'date'],
            'dateRevert' => ['reversible' => true,  'inverse' => 'dateRevert', 'category' => 'date'],
            'dateSql'    => ['reversible' => false, 'inverse' => null,     'category' => 'date'],

            // ISBD composers (lossy: subfield boundaries blurred).
            'isbdName'      => ['reversible' => false, 'inverse' => null,  'category' => 'unimarc'],
            'isbdNameColl'  => ['reversible' => false, 'inverse' => null,  'category' => 'unimarc'],
            'isbdMark'      => ['reversible' => false, 'inverse' => null,  'category' => 'unimarc'],

            // Unimarc converters (theoretically reversible on strict input).
            'unimarcIndex'             => ['reversible' => false, 'inverse' => null, 'category' => 'unimarc'],
            'unimarcCoordinates'       => ['reversible' => true,  'inverse' => null, 'category' => 'unimarc'],
            'unimarcCoordinatesHexa'   => ['reversible' => true,  'inverse' => null, 'category' => 'unimarc'],
            'unimarcTimeHexa'          => ['reversible' => false, 'inverse' => null, 'category' => 'unimarc'],
        ];
    }

    /**
     * Apply filter expressions to transform a value.
     *
     * @param string $pattern The full pattern containing filter expressions.
     * @param array $filterVars Variables for replacements.
     * @param array $filters List of filter expressions to process.
     * @param array $filterHasReplace Flags if filter has replacements.
     * @param array $replace Associative replacements for dynamic values.
     * @return string The processed result.
     */
    protected function applyFilters(
        string $pattern,
        array $filterVars,
        array $filters,
        array $filterHasReplace,
        array $replace
    ): string {
        static $varPatterns = [];

        $this->filterVars = $filterVars;

        // Build regex pattern for variable names, cached by names only, since
        // the values change for each value.
        if (count($this->filterVars)) {
            $serialized = implode("\0", array_keys($this->filterVars));
            if (!isset($varPatterns[$serialized])) {
                $r = [];
                foreach (array_keys($this->filterVars) as $v) {
                    $v = $this->valueToString($v);
                    $r[] = mb_substr($v, 0, 3) === '{{ '
                        ? preg_quote(mb_substr($v, 3, -3), '~')
                        : preg_quote($v, '~');
                }
                $varPatterns[$serialized] = implode('|', $r) . '|';
            }
            $this->patternVars = $varPatterns[$serialized];
        } else {
            $this->patternVars = '';
        }

        $filterResults = [];
        $filterKeys = array_flip($filters);
        $hasReplace = !empty($replace);

        foreach ($filters as $expression) {
            $hasReplaceExpr = $hasReplace && !empty($filterHasReplace[$filterKeys[$expression]]);
            $value = '';
            $parts = PatternParser::splitPipes(mb_substr((string) $expression, 3, -3));

            $isFirst = true;
            foreach ($parts as $part) {
                if ($isFirst) {
                    $isFirst = false;
                    // First part is the value expression, not a filter.
                    // If it's a replacement like {xxx}, resolve and use as value.
                    // Otherwise look up in filterVars or use as literal.
                    if ($hasReplaceExpr && preg_match('/^\{[^{}]+\}$/', $part)) {
                        // It's a replacement expression like {xxx}.
                        $value = $replace[$part] ?? '';
                    } else {
                        // It's a variable name or literal - use processFilter.
                        $value = $this->processFilter($value, $part);
                    }
                } else {
                    // Subsequent parts are filters - apply to current value.
                    $value = $hasReplaceExpr
                        ? $this->processFilter($value, strtr($part, $replace))
                        : $this->processFilter($value, $part);
                }
            }

            // Handle array results.
            if (is_array($value)) {
                $value = reset($value);
                $value = $this->valueToString($value);
            }

            $key = $hasReplaceExpr ? strtr($expression, $replace) : $expression;
            $filterResults[$key] = $value;
        }

        return strtr($pattern, $filterResults);
    }

    /**
     * Process a single filter on a value.
     *
     * @param mixed $value The input value (usually string, may be array).
     * @param string $filter The filter with arguments, e.g., "slice(1, 4)".
     * @return string|array The processed value.
     */
    protected function processFilter($value, string $filter)
    {
        // Parse filter name and arguments.
        if (preg_match('~\s*(?<function>[a-zA-Z0-9_]+)\s*\(\s*(?<args>.*?)\s*\)\s*~U', $filter, $matches)) {
            $function = $matches['function'];
            $args = $matches['args'];
        } else {
            $function = $filter;
            $args = '';
        }

        // Convert DOMNode to string, preserve arrays for last/first/join.
        if ($value instanceof \DOMNode) {
            $value = (string) $value->nodeValue;
        }
        $stringValue = is_array($value) ? $this->valueToString(reset($value)) : (string) $value;

        switch ($function) {
            case 'abs':
                return is_numeric($stringValue) ? (string) abs((float) $stringValue) : $stringValue;

            case 'basename':
                return basename($stringValue);

            case 'capitalize':
                return mb_strtoupper(mb_substr($stringValue, 0, 1)) . mb_substr($stringValue, 1);

            case 'date':
                try {
                    return $args === ''
                        ? (string) @strtotime($stringValue)
                        : (@date($args, @strtotime($stringValue)) ?: $stringValue);
                } catch (\Throwable $e) {
                    return $stringValue;
                }

            case 'e':
            case 'escape':
                return htmlspecialchars($stringValue, ENT_COMPAT | ENT_HTML5);

            case 'first':
                return is_array($value) ? $stringValue : mb_substr($stringValue, 0, 1);

            case 'format':
                $arga = $this->extractList($args);
                if ($arga) {
                    try {
                        return @vsprintf($stringValue, $arga) ?: $stringValue;
                    } catch (\Throwable $e) {
                        return $stringValue;
                    }
                }
                return $stringValue;

            case 'implode':
            case 'join':
                $arga = $this->extractList($args);
                if (count($arga)) {
                    $delimiter = array_shift($arga);
                    return implode($delimiter, $arga);
                }
                return '';

            case 'implodev':
                // Implode only real values, not empty string.
                $arga = $this->extractList($args);
                if (count($arga)) {
                    $arga = array_filter($arga, 'strlen');
                    // The string avoids strict type issue with empty array.
                    $delimiter = (string) array_shift($arga);
                    return implode($delimiter, $arga);
                }
                return '';

            case 'last':
                return is_array($value) ? (string) end($value) : mb_substr($stringValue, -1);

            case 'length':
                return (string) (is_array($value) ? count($value) : mb_strlen($stringValue));

            case 'lower':
                return mb_strtolower($stringValue);

            case 'replace':
                $arga = $this->extractAssociative($args);
                return $arga ? strtr($stringValue, $arga) : $stringValue;

            case 'slice':
                $arga = $this->extractList($args);
                $start = (int) ($arga[0] ?? 0);
                $length = (int) ($arga[1] ?? 1);
                return is_array($value)
                    ? array_slice($value, $start, $length, !empty($arga[2]))
                    : mb_substr($stringValue, $start, $length);

            case 'split':
                $arga = $this->extractList($args);
                $delimiter = $arga[0] ?? '';
                if (!isset($arga[1])) {
                    return strlen($delimiter)
                        ? explode($delimiter, $stringValue)
                        : $stringValue;
                }
                $limit = (int) $arga[1];
                return strlen($delimiter)
                    ? explode($delimiter, $stringValue, $limit)
                    : mb_str_split($stringValue, max(1, $limit));

            case 'striptags':
                return strip_tags($stringValue);

            case 'table':
                return $this->processTableFilter($stringValue, $args);

            case 'table_reverse':
                return $this->processTableReverseFilter($stringValue, $args);

            case 'title':
                return preg_replace_callback('~(^|\s)(\p{Ll})~u', fn ($m) => $m[1] . mb_strtoupper($m[2]), $stringValue) ?? $stringValue;

            case 'translate':
                return $this->translator ? $this->translator->translate($stringValue) : $stringValue;

            case 'trim':
                $arga = $this->extractList($args);
                $mask = $arga[0] ?? '';
                if (!strlen($mask)) {
                    $mask = " \t\n\r\0\x0B";
                }
                $side = $arga[1] ?? '';
                if ($side === 'left') {
                    return ltrim($stringValue, $mask);
                } elseif ($side === 'right') {
                    return rtrim($stringValue, $mask);
                }
                return trim($stringValue, $mask);

            case 'upper':
                return mb_strtoupper($stringValue);

            case 'url_encode':
                return rawurlencode($stringValue);

            // Domain-specific filters.

            // TODO Add a "dateFormat" with a dynamic format.

            case 'dateIso':
                return $this->filterDateIso($stringValue);

            case 'dateRevert':
                return $this->filterDateRevert($stringValue);

            case 'dateSql':
                return $this->filterDateSql($stringValue);

            case 'isbdName':
                return $this->filterIsbdName($args);

            case 'isbdNameColl':
                return $this->filterIsbdNameColl($args);

            case 'isbdMark':
                return $this->filterIsbdMark($args);

            case 'unimarcIndex':
                return $this->filterUnimarcIndex($stringValue, $args);

            case 'unimarcCoordinates':
                return $this->filterUnimarcCoordinates($stringValue);

            case 'unimarcCoordinatesHexa':
                return $this->filterUnimarcCoordinatesHexa($stringValue);

            case 'unimarcTimeHexa':
                return $this->filterUnimarcTimeHexa($stringValue);

            // Variable or unknown filter - check if it's a variable.
            case 'value':
            default:
                return $this->filterVars['{{ ' . $filter . ' }}']
                    ?? $this->filterVars[$filter]
                    ?? $value;
        }
    }

    /**
     * Process table lookup filter.
     *
     * May be:
     * - an inline table: table({'key': 'value', ...})
     * - a named table: table(name, type, strict).
     *
     * Type "code" is used to get the code (first column).
     * By default get label from code, so the second column.
     * Strict means to not check without diacritics.
     */
    protected function processTableFilter(string $value, string $args): string
    {
        $first = mb_substr($args, 0, 1);

        // Inline table: table({'key': 'value', ...}).
        if ($first === '{') {
            $table = $this->extractAssociative(trim(mb_substr($args, 1, -1)));
            return $table[$value] ?? $value;
        }

        // Named table: table(name, type, strict).
        $arga = $this->extractList($args);
        $name = $arga[0] ?? '';

        // Check for ISO tables.
        if (class_exists('Iso639p3\Iso639p3')) {
            if ($name === 'iso-639-native') {
                return \Iso639p3\Iso639p3::name($value) ?: $value;
            } elseif ($name === 'iso-639-english') {
                return \Iso639p3\Iso639p3::englishName($value) ?: $value;
            } elseif ($name === 'iso-639-english-inverted') {
                return \Iso639p3\Iso639p3::englishInvertedName($value) ?: $value;
            } elseif ($name === 'iso-639-french') {
                return \Iso639p3\Iso639p3::frenchName($value) ?: $value;
            } elseif ($name === 'iso-639-french-inverted') {
                return \Iso639p3\Iso639p3::frenchInvertedName($value) ?: $value;
            }
        }

        if (class_exists('Iso3166p1\Iso3166p1')) {
            if ($name === 'iso-3166-native') {
                return \Iso3166p1\Iso3166p1::name($value) ?: $value;
            } elseif ($name === 'iso-3166-english') {
                return \Iso3166p1\Iso3166p1::englishName($value) ?: $value;
            } elseif ($name === 'iso-3166-french') {
                return \Iso3166p1\Iso3166p1::frenchName($value) ?: $value;
            }
        }

        // External table lookup via mapperConfig if available.
        if (isset($this->mapperConfig) && method_exists($this->mapperConfig, 'getSectionSettingSub')) {
            return $this->mapperConfig->getSectionSettingSub('tables', $name, $value, $value);
        }

        return $value;
    }

    /**
     * Process reverse table lookup filter for export direction.
     *
     * Symmetric counterpart of processTableFilter(): given a label, returns the
     * corresponding code. The reverse table is built on the fly by flipping the
     * original table; collisions on values produce a notice in the logger when
     * one is available, but the first matching key still wins (deterministic
     * via array_flip).
     *
     * May be:
     * - inline table: table_reverse({'key': 'value', ...}) — flipped on call
     * - named table: table_reverse(name) — fetched then flipped
     */
    protected function processTableReverseFilter(string $value, string $args): string
    {
        $first = mb_substr($args, 0, 1);

        if ($first === '{') {
            $table = $this->extractAssociative(trim(mb_substr($args, 1, -1)));
            $reverse = $this->reverseTable($table, '<inline>');
            return $reverse[$value] ?? $value;
        }

        $arga = $this->extractList($args);
        $name = $arga[0] ?? '';
        if ($name === '' || !isset($this->mapperConfig) || !method_exists($this->mapperConfig, 'getSection')) {
            return $value;
        }

        // The cache is keyed by content, since another mapping may have a table
        // with the same name.
        static $cache = [];
        $table = $this->mapperConfig->getSection('tables')[$name] ?? [];
        $key = md5(serialize($table));
        if (!isset($cache[$key])) {
            $cache[$key] = $this->reverseTable($table, $name);
        }

        return $cache[$key][$value] ?? $value;
    }

    /**
     * Build a reverse lookup from a table, logging non-bijective collisions.
     */
    protected function reverseTable(array $table, string $name): array
    {
        if (!$table) {
            return [];
        }

        $reverse = [];
        $collisions = [];
        foreach ($table as $key => $val) {
            $val = (string) $val;
            if (isset($reverse[$val])) {
                $collisions[$val][] = (string) $key;
            } else {
                $reverse[$val] = (string) $key;
            }
        }

        if ($collisions && isset($this->logger)) {
            $this->logger->notice(sprintf(
                'Mapper: table "%s" is not bijective; %d value(s) map to multiple keys, first wins for table_reverse.', // @translate
                $name,
                count($collisions)
            ));
        }

        return $reverse;
    }

    /**
     * Convert Unimarc date to ISO format.
     *
     * Examples:
     * "d1605110512" => "1605-11-05T12"
     * "[1984]-" => kept.
     *
     * Missing numbers may be set as "u", but not manageable as iso 8601.
     * The first character may be a space to manage Unimarc.
     */
    protected function filterDateIso(string $value): string
    {
        if (!mb_strlen($value) || mb_strpos($value, 'u') !== false) {
            return $value;
        }

        $firstChar = mb_substr($value, 0, 1);
        if (!in_array($firstChar, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '-', '+', 'c', 'd', ' '])) {
            return $value;
        }

        $prefix = '';
        if (in_array($firstChar, ['-', '+', 'c', 'd', ' '])) {
            $prefix = ($firstChar === '-' || $firstChar === 'c') ? '-' : '';
            $value = mb_substr($value, 1);
        }

        $result = $prefix
            . mb_substr($value, 0, 4) . '-' . mb_substr($value, 4, 2) . '-' . mb_substr($value, 6, 2)
            . 'T' . mb_substr($value, 8, 2) . ':' . mb_substr($value, 10, 2) . ':' . mb_substr($value, 12, 2);

        return rtrim($result, '-:T |#');
    }

    /**
     * Convert spreadsheet date format to ISO.
     *
     * Default spreadsheet is usualy day-month-year or year only.
     * "dd/mm/yy" => "yyyy-mm-dd"
     */
    protected function filterDateRevert(string $value): string
    {
        $value = trim($value);
        $matches = [];
        preg_match('/\D/', $value, $matches);
        $sep = mb_substr($matches[0] ?? '', 0, 1);

        if (mb_strlen($sep)) {
            $day = (int) strtok($value, $sep);
            $month = (int) strtok($sep);
            $year = strtok($sep);
            $year = (int) (mb_strlen($year) === 2 ? '20' . $year : $year);
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return (mb_strlen($value) === 6 ? '20' . mb_substr($value, 4, 2) : mb_substr($value, 4, 4))
            . '-' . mb_substr($value, 2, 2) . '-' . mb_substr($value, 0, 2);
    }

    /**
     * Convert Unimarc 005 to SQL datetime.
     *
     * "19850901141236.0" => "1985-09-01 14:12:36"
     */
    protected function filterDateSql(string $value): string
    {
        $value = trim($value);
        return mb_substr($value, 0, 4) . '-' . mb_substr($value, 4, 2) . '-' . mb_substr($value, 6, 2)
            . ' ' . mb_substr($value, 8, 2) . ':' . mb_substr($value, 10, 2) . ':' . mb_substr($value, 12, 2);
    }

    /**
     * Format ISBD name for persons (Unimarc 700+).
     *
     * Function: isbdName(a, b, c, d, f, g, k, o, p, 5).
     * Unimarc 700 et suivants :
     * $a Élément d’entrée
     * $b Partie du nom autre que l’élément d’entrée
     * $c Eléments ajoutés aux noms autres que les dates
     * $d Chiffres romains
     * $f Dates
     * $g Développement des initiales du prénom
     * $k Qualificatif pour l’attribution
     * $o Identifiant international du nom
     * $p Affiliation / adresse
     * $5 Institution à laquelle s’applique la zone
     */
    protected function filterIsbdName(string $args): string
    {
        $arga = $this->extractList($args, ['a', 'b', 'c', 'd', 'f', 'g', 'k', 'o', 'p', '5']);
        return $arga['a']
            . ($arga['b'] ? ', ' . $arga['b'] : '')
            . ($arga['g'] ? ' (' . $arga['g'] . ')' : '')
            . ($arga['d'] ? ', ' . $arga['d'] : '')
            . ($arga['f']
                ? ' (' . $arga['f'] . ($arga['c'] ? ' ; ' . $arga['c'] : '') . ($arga['k'] ? ' ; ' . $arga['k'] : '') . ')'
                : ($arga['c']
                    ? ' (' . $arga['c'] . ($arga['k'] ? ' ; ' . $arga['k'] : '') . ')'
                    : ($arga['k'] ? ' (' . $arga['k'] . ')' : '')))
            . ($arga['o'] ? ' {' . $arga['o'] . '}' : '')
            . ($arga['p'] ? ', ' . $arga['p'] : '')
            . ($arga['5'] ? ', ' . $arga['5'] : '');
    }

    /**
     * Format ISBD name for organizations (Unimarc 710/720/740).
     *
     * Function: isbdNameColl(a, b, c, d, e, f, g, h, o, p, r, 5).
     * Unimarc 710/720/740 et suivants :
     * $a Élément d’entrée
     * $b Subdivision
     * $c Élément ajouté au nom ou qualificatif
     * $d Numéro de congrès et/ou numéro de session de congrès
     * $e Lieu du congrès
     * $f Date du congrès
     * $g Élément rejeté
     * $h Partie du nom autre que l’élément d’entrée et autre que l’élément rejeté
     * $o Identifiant international du nom
     * $p Affiliation / adresse
     * $r Partie ou rôle joué
     * $5 Institution à laquelle s’applique la zone
     * // Pour mémoire.
     * $3 Identifiant de la notice d’autorité
     * $4 Code de fonction
     */
    protected function filterIsbdNameColl(string $args): string
    {
        $arga = $this->extractList($args, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'o', 'p', 'r', '5']);
        // TODO Improve isbd for organizations.
        return $arga['a']
            . ($arga['b'] ? ', ' . $arga['b'] : '')
            . ($arga['g']
                ? ' (' . $arga['g'] . ($arga['h'] ? ' ; ' . $arga['h'] : '') . ')'
                : ($arga['h'] ? ' (' . $arga['h'] . ')' : ''))
            . ($arga['d'] ? ', ' . $arga['d'] : '')
            . ($arga['e'] ? ', ' . $arga['e'] : '')
            . ($arga['f']
                ? ' (' . $arga['f'] . ($arga['c'] ? ' ; ' . $arga['c'] : '') . ')'
                : ($arga['c'] ? ' (' . $arga['c'] . ')' : ''))
            . ($arga['o'] ? ' {' . $arga['o'] . '}' : '')
            . ($arga['p'] ? ', ' . $arga['p'] : '')
            . ($arga['r'] ? ', ' . $arga['r'] : '')
            . ($arga['5'] ? ', ' . $arga['5'] : '');
    }

    /**
     * Format ISBD mark (Unimarc 716).
     *
     * Function: isbdMark(a, b, c).
     * $a Élément d’entrée
     * $c Qualificatif
     * $f Dates
     */
    protected function filterIsbdMark(string $args): string
    {
        $arga = $this->extractList($args, ['a', 'b', 'c']);
        return $arga['a']
            . ($arga['b'] ? ', ' . $arga['b'] : '')
            . ($arga['c'] ? ' (' . $arga['c'] . ')' : '');
    }

    /**
     * Create Unimarc index uri.
     *
     * Unimarc Annexe G.
     * @link https://www.transition-bibliographique.fr/wp-content/uploads/2018/07/AnnexeG-5-2007.pdf
     */
    protected function filterUnimarcIndex(string $value, string $args): string
    {
        $arga = $this->extractList($args);
        $index = $arga[0] ?? '';
        if (!$index) {
            return $value;
        }

        $code = count($arga) === 1 ? $value : ($arga[1] ?? '');

        switch ($index) {
            case 'unimarc/a':
                return 'Unimarc/A : ' . $code;
            case 'rameau':
                return 'https://data.bnf.fr/ark:/12148/cb' . $code . $this->noidCheckBnf('cb' . $code);
            default:
                return $index . ' : ' . $code;
        }
    }

    /**
     * Convert Unimarc coordinates.
     *
     * "w0241207" => "W 24°12'7""
     * Hemisphere "+" / "-" too.
     */
    protected function filterUnimarcCoordinates(string $value): string
    {
        $firstChar = mb_strtoupper(mb_substr($value, 0, 1));
        $mapping = ['+' => 'N', '-' => 'S', 'W' => 'W', 'E' => 'E', 'N' => 'N', 'S' => 'S'];
        return ($mapping[$firstChar] ?? '?') . ' '
            . intval(mb_substr($value, 1, 3)) . '°'
            . intval(mb_substr($value, 4, 2)) . "'"
            . intval(mb_substr($value, 6, 2)) . '"';
    }

    /**
     * Convert Unimarc hexadecimal coordinates.
     */
    protected function filterUnimarcCoordinatesHexa(string $value): string
    {
        return mb_substr($value, 0, 2) . '°' . mb_substr($value, 2, 2) . "'" . mb_substr($value, 4, 2) . '"';
    }

    /**
     * Convert Unimarc time.
     * "150027" => "15h0m27s"
     */
    protected function filterUnimarcTimeHexa(string $value): string
    {
        $h = (int) trim(mb_substr($value, 0, 2));
        $m = (int) trim(mb_substr($value, 2, 2));
        $s = (int) trim(mb_substr($value, 4, 2));
        return ($h ? $h . 'h' : '')
            . ($m ? $m . 'm' : ($h && $s ? '0m' : ''))
            . ($s ? $s . 's' : '');
    }

    /**
     * Extract a list of arguments from a string.
     */
    protected function extractList(string $args, array $keys = []): array
    {
        $matches = [];

        // Args can be a string between double quotes, or a string between
        // single quotes, or a positive/negative float number.
        preg_match_all('~\s*(?<args>' . $this->patternVars . '"[^"]*?"|\'[^\']*?\'|[+-]?(?:\d*\.)?\d+)\s*,?\s*~', $args, $matches);

        $result = [];
        foreach ($matches['args'] as $key => $arg) {
            // If this is a var, take it, else this is a string or a number,
            // so remove the quotes if any.
            $result[$key] = $this->filterVars['{{ ' . $arg . ' }}']
                ?? (is_numeric($arg) ? $arg : mb_substr($arg, 1, -1));
        }

        if (!count($keys)) {
            return $result;
        }

        $countKeys = count($keys);
        return array_combine(
            $keys,
            count($result) >= $countKeys
                ? array_slice($result, 0, $countKeys)
                : array_pad($result, $countKeys, '')
        );
    }

    /**
     * Extract associative key-value pairs from arguments.
     */
    protected function extractAssociative(string $args): array
    {
        // TODO Improve the regex to extract keys and values directly.
        $matches = [];
        preg_match_all('~\s*(?<args>' . $this->patternVars . '"[^"]*?"|\'[^\']*?\'|[+-]?(?:\d*\.)?\d+)\s*,?\s*~', $args, $matches);

        $output = [];
        foreach (array_chunk($matches['args'], 2) as $keyValue) {
            if (count($keyValue) === 2) {
                // The key cannot be a value, but may be numeric.
                $key = is_numeric($keyValue[0]) ? $keyValue[0] : mb_substr($keyValue[0], 1, -1);
                $value = $this->filterVars['{{ ' . $keyValue[1] . ' }}']
                    ?? (is_numeric($keyValue[1]) ? $keyValue[1] : mb_substr($keyValue[1], 1, -1));
                $output[$key] = $value;
            }
        }
        return $output;
    }

    /**
     * Compute the check character for BnF noid records.
     *
     * The records linked with BnF use only the code, without the check
     * character, so it should be computed in order to get the uri.
     *
     * Unlike noid recommendation, the check for bnf doesn't use naan ("12148").
     *
     * @see https://metacpan.org/dist/Noid/view/noid#NOID-CHECK-DIGIT-ALGORITHM
     *
     */
    protected function noidCheckBnf(string $value): string
    {
        $table = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'm', 'n', 'p', 'q', 'r', 's', 't', 'v', 'w', 'x', 'z'];
        $tableKeys = array_flip($table);
        $vals = str_split($value, 1);
        $sum = array_sum(array_map(fn ($k, $v) => ($tableKeys[$v] ?? 0) * ($k + 1), array_keys($vals), array_values($vals)));
        return $table[$sum % count($table)];
    }

    /**
     * Convert a value to string, handling DOMNode objects and arrays.
     */
    protected function valueToString($value): string
    {
        if ($value instanceof \DOMNode) {
            return (string) $value->nodeValue;
        }
        if (is_array($value)) {
            $value = reset($value);
            return $value instanceof \DOMNode
                ? (string) $value->nodeValue
                : (string) $value;
        }
        return (string) $value;
    }
}
