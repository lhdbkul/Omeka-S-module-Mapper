<?php declare(strict_types=1);

/**
 * TwigXmlEngine - Minimal Twig-flavored template engine for XML output.
 *
 * Supports the subset that matters for Twig-XML postprocess templates:
 *
 *   {{ var }}                 variable interpolation, auto-escaped for XML
 *   {{ var|filter }}          filters from FilterTrait (upper, lower, trim,
 *                             escape, url_encode, table, etc.) + |raw to bypass
 *                             auto-escape
 *   {% if expr %}...{% endif %}      conditional (truthy: not null/empty)
 *   {% if expr %}...{% else %}...{% endif %}
 *   {% for item in collection %}...{% endfor %}
 *
 * Variable resolution uses dot notation: a.b.c. Conditions are simple
 * existence/truthy checks; no boolean operators (use nested {% if %} when
 * needed). Loops iterate over arrays only; non-array values are skipped.
 *
 * Auto-escape is enabled by default to keep XML well-formed. Use |raw to inject
 * pre-escaped content (typically nested template results).
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

use Laminas\Mvc\I18n\Translator;

class TwigXmlEngine
{
    use FilterTrait;

    /**
     * @var \Laminas\Mvc\I18n\Translator|null
     */
    protected $translator;

    /**
     * Optional MapperConfig used by table filters.
     *
     * @var \Mapper\Stdlib\MapperConfig|null
     */
    protected $mapperConfig;

    public function __construct(?Translator $translator = null, ?MapperConfig $mapperConfig = null)
    {
        $this->translator = $translator;
        $this->mapperConfig = $mapperConfig;
    }

    /**
     * Render a template with the given context.
     */
    public function render(string $template, array $context): string
    {
        $tokens = $this->tokenize($template);
        [$nodes] = $this->parse($tokens, 0);
        return $this->renderNodes($nodes, $context);
    }

    /**
     * Split the template into a flat list of TEXT / VAR / TAG tokens.
     */
    protected function tokenize(string $template): array
    {
        // Strip {# ... #} comments first (multi-line, non-greedy).
        $template = preg_replace('/\{#.*?#\}/s', '', $template);
        // Capture {% ... %} and {{ ... }} delimiters (single-line content).
        // The expressions may contain braces, like "replace({'a': 'b'})".
        $pattern = '/(\{%[^%]*%\}|\{\{.*?\}\})/';
        $parts = preg_split($pattern, $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\{%\s*(if|for|else|endif|endfor)\b\s*(.*?)\s*%\}$/s', $part, $m)) {
                $tokens[] = ['type' => $m[1], 'expr' => $m[2]];
                continue;
            }
            if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/s', $part, $m)) {
                $tokens[] = ['type' => 'var', 'expr' => $m[1]];
                continue;
            }
            $tokens[] = ['type' => 'text', 'value' => $part];
        }
        return $tokens;
    }

    /**
     * Parse a flat token list into an AST. Stops on any of $stopAt types and
     * returns the consumed index so the caller can continue parsing.
     *
     * @return array{0: array, 1: int}
     */
    protected function parse(array $tokens, int $i, array $stopAt = []): array
    {
        $nodes = [];
        $count = count($tokens);
        while ($i < $count) {
            $token = $tokens[$i];
            if ($stopAt && in_array($token['type'], $stopAt, true)) {
                return [$nodes, $i];
            }
            switch ($token['type']) {
                case 'text':
                    $nodes[] = ['type' => 'text', 'value' => $token['value']];
                    $i++;
                    break;
                case 'var':
                    $nodes[] = ['type' => 'var', 'expr' => $token['expr']];
                    $i++;
                    break;
                case 'if':
                    [$body, $i] = $this->parse($tokens, $i + 1, ['else', 'endif']);
                    $elseBody = [];
                    if ($i < $count && $tokens[$i]['type'] === 'else') {
                        [$elseBody, $i] = $this->parse($tokens, $i + 1, ['endif']);
                    }
                    if ($i < $count && $tokens[$i]['type'] === 'endif') {
                        $i++;
                    }
                    $nodes[] = ['type' => 'if', 'expr' => $token['expr'], 'body' => $body, 'else' => $elseBody];
                    break;
                case 'for':
                    [$body, $i] = $this->parse($tokens, $i + 1, ['endfor']);
                    if ($i < $count && $tokens[$i]['type'] === 'endfor') {
                        $i++;
                    }
                    $nodes[] = ['type' => 'for', 'expr' => $token['expr'], 'body' => $body];
                    break;
                default:
                    // Stray else/endif/endfor outside their parent: ignore.
                    $i++;
                    break;
            }
        }
        return [$nodes, $i];
    }

    /**
     * Render an AST node list with the given context.
     */
    protected function renderNodes(array $nodes, array $context): string
    {
        $out = '';
        foreach ($nodes as $node) {
            switch ($node['type']) {
                case 'text':
                    $out .= $node['value'];
                    break;
                case 'var':
                    $out .= $this->evalVar($node['expr'], $context);
                    break;
                case 'if':
                    $out .= $this->evalCondition($node['expr'], $context)
                        ? $this->renderNodes($node['body'], $context)
                        : $this->renderNodes($node['else'], $context);
                    break;
                case 'for':
                    $out .= $this->evalFor($node, $context);
                    break;
            }
        }
        return $out;
    }

    /**
     * Render a {% for x in collection %} block.
     */
    protected function evalFor(array $node, array $context): string
    {
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+?)\s*$/', $node['expr'], $m)) {
            return '';
        }
        $itemVar = $m[1];
        $collection = $this->resolve($m[2], $context);
        if (!is_array($collection)) {
            return '';
        }
        $out = '';
        $index = 0;
        $length = count($collection);
        foreach ($collection as $key => $value) {
            $loopCtx = $context;
            $loopCtx[$itemVar] = $value;
            $loopCtx['loop'] = [
                'index0' => $index,
                'index' => $index + 1,
                'key' => $key,
                'first' => $index === 0,
                'last' => $index === $length - 1,
                'length' => $length,
            ];
            $out .= $this->renderNodes($node['body'], $loopCtx);
            ++$index;
        }
        return $out;
    }

    /**
     * Evaluate a {{ expr }} interpolation, applying filters and auto-escape.
     */
    protected function evalVar(string $expr, array $context): string
    {
        $parts = PatternParser::splitPipes($expr);
        $name = (string) array_shift($parts);

        // Allow string and number literals as the head expression.
        $value = $this->evalLiteral($name);
        if ($value === null) {
            $value = $this->resolve($name, $context);
        }

        $autoEscape = true;
        foreach ($parts as $filter) {
            if ($filter === 'raw') {
                $autoEscape = false;
                continue;
            }
            // The value is escaped once at the end.
            if ($filter === 'e' || $filter === 'escape' || strncmp($filter, 'e(', 2) === 0 || strncmp($filter, 'escape(', 7) === 0) {
                continue;
            }
            $value = $this->processFilter($value, $filter);
        }

        if ($value === null || $value === false) {
            return '';
        }
        if (is_array($value)) {
            $value = (string) reset($value);
        } else {
            $value = (string) $value;
        }
        return $autoEscape ? htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') : $value;
    }

    /**
     * Evaluate the truthiness of an {% if %} expression.
     */
    protected function evalCondition(string $expr, array $context): bool
    {
        $value = $this->resolve(trim($expr), $context);
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === '0') {
            return false;
        }
        if (is_array($value)) {
            return !empty($value);
        }
        return true;
    }

    /**
     * Resolve a dot-notation path against the context.
     */
    protected function resolve(string $expr, array $context)
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        $parts = explode('.', $expr);
        $cursor = $context;
        foreach ($parts as $part) {
            if (is_array($cursor) && array_key_exists($part, $cursor)) {
                $cursor = $cursor[$part];
            } else {
                return null;
            }
        }
        return $cursor;
    }

    /**
     * Recognize quoted strings and numeric literals; return the value or null.
     */
    protected function evalLiteral(string $expr)
    {
        $expr = trim($expr);
        $first = substr($expr, 0, 1);
        $last = substr($expr, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($expr, 1, -1);
        }
        if (is_numeric($expr)) {
            return $expr + 0;
        }
        return null;
    }
}
