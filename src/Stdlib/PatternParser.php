<?php declare(strict_types=1);

/**
 * PatternParser - Parses pattern strings for substitutions and filters.
 *
 * Handles two types of expressions:
 *
 * 1. PSR-3 style substitution: {key}
 *    Simple placeholder replacement from data array.
 *    Example: "Item {id}" + ['id' => '123'] → "Item 123"
 *
 * 2. Twig-style variables and filters: {{ variable }} or {{ variable|filter }}
 *    Access to context variables with optional filter transformations.
 *    Example: "{{ value|upper }}" → "HELLO"
 *
 * Combined: {{ {key}|filter }} - Substitution then filter.
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

class PatternParser
{
    /**
     * Result structure for parsed patterns.
     */
    public const EMPTY_RESULT = [
        'pattern' => '',
        'replace' => [],
        'filters' => [],
        'filters_has_replace' => [],
        'is_simple' => true,
        'has_filters' => false,
    ];

    /**
     * Parse a pattern string for replacements and filter expressions.
     *
     * @param string $pattern The pattern string to parse.
     * @return array Parsed pattern with replace, filters arrays.
     */
    public function parse(string $pattern): array
    {
        $result = self::EMPTY_RESULT;
        $result['pattern'] = $pattern;

        if (!strlen($pattern)) {
            return $result;
        }

        // Step 1: Find all {{ ... }} expressions (double braces).
        $doubleBraceMatches = $this->findDoubleBraceExpressions($pattern);

        foreach ($doubleBraceMatches as $match) {
            $hasFilter = mb_strpos($match, '|') !== false;
            $hasInnerReplace = $this->hasSingleBraceInside($match);

            // Treat as filter expression if:
            // - Contains | (explicit filter), or
            // - Contains {xxx} replacement (needs filter processing to resolve)
            if ($hasFilter || $hasInnerReplace) {
                $result['filters'][] = $match;
                $result['has_filters'] = true;
                $result['filters_has_replace'][] = $hasInnerReplace;
            } else {
                $result['replace'][] = $match;
            }
        }

        // Step 2: Find simple {path} replacements (single braces).
        $singleBraceMatches = $this->findSingleBraceExpressions($pattern);

        foreach ($singleBraceMatches as $match) {
            // Skip if already processed.
            if (in_array($match, $result['replace'])) {
                continue;
            }

            // Add to replace array. Even if inside a double-brace expression,
            // we need the replacement value for filter processing.
            $result['replace'][] = $match;
        }

        // Determine if simple pattern (no twig, just replacements).
        $result['is_simple'] = empty($result['filters']);

        return $result;
    }

    /**
     * Extract the path from a replacement expression.
     *
     * @param string $expression Expression like "{path}" or "{{ path }}".
     * @return string The path without braces.
     */
    public function extractPath(string $expression): string
    {
        // Remove {{ }} or { }.
        $path = trim($expression);

        // Handle double braces first.
        if (mb_substr($path, 0, 2) === '{{' && mb_substr($path, -2) === '}}') {
            $path = mb_substr($path, 2, -2);
        } elseif (mb_substr($path, 0, 1) === '{' && mb_substr($path, -1) === '}') {
            $path = mb_substr($path, 1, -1);
        }

        // Remove filter part if present.
        $pipePos = mb_strpos($path, '|');
        if ($pipePos !== false) {
            $path = mb_substr($path, 0, $pipePos);
        }

        return trim($path);
    }

    /**
     * Extract the filter chain from a filter expression.
     *
     * @param string $expression Expression like "{{ value|upper|lower }}".
     * @return array Array of filter names.
     */
    public function extractFilters(string $expression): array
    {
        // Remove braces.
        $inner = $this->extractPath($expression);

        // Check if this was a filter expression.
        $pipePos = mb_strpos($expression, '|');
        if ($pipePos === false) {
            return [];
        }

        // Get the filter part.
        $filterPart = trim(mb_substr($expression, $pipePos + 1));
        $filterPart = rtrim($filterPart, '} ');

        // Parse filter chain.
        $filters = [];
        $parts = self::splitPipes($filterPart);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Extract filter name (before any arguments in parentheses).
            $parenPos = mb_strpos($part, '(');
            if ($parenPos !== false) {
                $filters[] = trim(mb_substr($part, 0, $parenPos));
            } else {
                $filters[] = $part;
            }
        }

        return $filters;
    }

    /**
     * Check if a pattern contains any replacements or filter expressions.
     */
    public function hasExpressions(string $pattern): bool
    {
        // Quick check for any braces.
        return mb_strpos($pattern, '{') !== false;
    }

    /**
     * Check if a pattern is a simple literal (no replacements).
     */
    public function isLiteral(string $pattern): bool
    {
        return mb_strpos($pattern, '{') === false;
    }

    /**
     * Check if a pattern is a simple single replacement.
     *
     * @param string $pattern The pattern to check.
     * @return bool True if the pattern is just a single replacement.
     */
    public function isSingleReplacement(string $pattern): bool
    {
        $trimmed = trim($pattern);

        // Check for {{ path }}.
        if (preg_match('/^\{\{\s*[^|{}]+\s*\}\}$/', $trimmed)) {
            return true;
        }

        // Check for { path }.
        if (preg_match('/^\{[^{}]+\}$/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * Build a pattern string from components.
     *
     * @param string|null $prepend Prefix to add.
     * @param string $main Main content or replacement.
     * @param string|null $append Suffix to add.
     * @return string The complete pattern.
     */
    public function buildPattern(?string $prepend, string $main, ?string $append): string
    {
        $parts = [];

        if ($prepend !== null && $prepend !== '') {
            $parts[] = $prepend;
        }

        $parts[] = $main;

        if ($append !== null && $append !== '') {
            $parts[] = $append;
        }

        return implode('', $parts);
    }

    /**
     * Split a filter chain on "|", except inside quotes, parentheses, brackets
     * and braces, like in "value|replace({'|': ' '})|join('|')".
     *
     * @return string[] Trimmed parts, without empty ones.
     */
    public static function splitPipes(string $expression): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if ($quote !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $char . $expression[++$i];
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                ++$depth;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '|' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);
        return array_values(array_filter($parts, 'strlen'));
    }

    /**
     * Find all {{ ... }} expressions in a pattern.
     *
     * Uses non-greedy matching to handle nested structures.
     */
    protected function findDoubleBraceExpressions(string $pattern): array
    {
        $matches = [];
        // Use non-greedy .*? to handle nested braces.
        preg_match_all('/\{\{.+?\}\}/s', $pattern, $matches);
        return $matches[0] ?? [];
    }

    /**
     * Find all {path} expressions in a pattern (single braces only).
     *
     * Matches valid replacement patterns like {key}, {path/to/value},
     * {dcterms:title}, {état}. Does NOT match { value } (spaces) or
     * {'key': 'val'} (JSON-style).
     */
    protected function findSingleBraceExpressions(string $pattern): array
    {
        $matches = [];
        // Match {identifier} where identifier is a valid path. Starts with
        // letter/underscore (unicode-aware), can contain letters, numbers,
        // underscore, colon, dot, at sign, slash, hyphen. The at sign is needed
        // for Omeka value paths like {dcterms:title.0.@value}. Uses \p{L} for
        // unicode letters and \p{N} for unicode numbers.
        preg_match_all('/\{[\p{L}_][\p{L}\p{N}_:.@\\/\\-]*\}/u', $pattern, $matches);
        return $matches[0] ?? [];
    }

    /**
     * Check if a double-brace expr contains single-brace replacements.
     *
     * Example: {{ {path}|filter }} has single-brace inside.
     */
    protected function hasSingleBraceInside(string $doubleBrace): bool
    {
        // Remove the outer {{ }}.
        $inner = mb_substr($doubleBrace, 2, -2);
        // Check for valid replacement pattern {identifier} (unicode-aware).
        return (bool) preg_match('/\{[\p{L}_][\p{L}\p{N}_:.@\\/\\-]*\}/u', $inner);
    }
}
