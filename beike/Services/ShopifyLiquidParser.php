<?php

namespace Beike\Services;

/**
 * Small, bounded Liquid tokenizer/parser used for migration analysis.
 *
 * It intentionally produces structured tokens instead of treating a Liquid
 * file as a collection of regex matches. It is an analysis parser, not a
 * Shopify runtime: unsupported constructs stay visible in the manifest.
 */
class ShopifyLiquidParser
{
    /** @return array{tokens: array, tags: array, filters: array, objects: array, dependencies: array, schema: ?array, warnings: array} */
    public function inspect(string $source): array
    {
        $tokens       = $this->tokenize($source);
        $tags         = [];
        $filters      = [];
        $objects      = [];
        $dependencies = [];
        $warnings     = [];
        $schema       = null;
        $stack        = [];

        foreach ($tokens as $token) {
            if ($token['kind'] === 'output') {
                $expression = $this->expression($token['value']);
                foreach ($expression['objects'] as $object) {
                    $objects[$object] = true;
                }
                foreach ($expression['filters'] as $filter) {
                    $filters[$filter] = true;
                }
                foreach ($this->assetDependencies($expression) as $dependency) {
                    $dependencies[$dependency] = true;
                }

                continue;
            }
            if ($token['kind'] !== 'tag') {
                continue;
            }
            $tag = $this->tag($token['value']);
            if ($tag['name'] === '') {
                continue;
            }
            $tags[$tag['name']] = true;
            if (in_array($tag['name'], ['if', 'unless', 'case', 'for', 'capture', 'form', 'paginate', 'style', 'javascript', 'schema', 'comment'], true)) {
                $stack[] = $tag['name'];
            }
            if ($tag['name'] === 'enddoc') {
                // Shopify's documentation tag is non-rendering metadata and
                // may be used in a single-tag form.
                continue;
            }
            if (str_starts_with($tag['name'], 'end')) {
                $expected = substr($tag['name'], 3);
                if (($stack[count($stack) - 1] ?? null) === $expected) {
                    array_pop($stack);
                } else {
                    $warnings[] = "Unbalanced Liquid tag: {$tag['name']}";
                }
            }
            if (in_array($tag['name'], ['render', 'include'], true) && $tag['argument'] !== '' && $tag['argument'] !== 'block') {
                $dependencies['snippets/' . $tag['argument'] . '.liquid'] = true;
            }
            if ($tag['name'] === 'schema') {
                $json = $this->schemaAfter($tokens, $token['offset']);
                if ($json !== null) {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $schema = $decoded;
                    } else {
                        $warnings[] = 'Section schema is not valid JSON.';
                    }
                }
            }
        }
        foreach ($stack as $tag) {
            $warnings[] = "Unclosed Liquid tag: {$tag}";
        }

        return [
            'tokens'       => $tokens,
            'tags'         => array_keys($tags),
            'filters'      => array_keys($filters),
            'objects'      => array_keys($objects),
            'dependencies' => array_keys($dependencies),
            'schema'       => $schema,
            'warnings'     => array_values(array_unique($warnings)),
        ];
    }

    /** @return array<int, array{kind: string, value: string, offset: int}> */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $cursor = 0;
        $length = strlen($source);
        while ($cursor < $length) {
            $output = strpos($source, '{{', $cursor);
            $tag    = strpos($source, '{%', $cursor);
            $start  = min($output === false ? $length : $output, $tag === false ? $length : $tag);
            if ($start > $cursor) {
                $tokens[] = ['kind' => 'text', 'value' => substr($source, $cursor, $start - $cursor), 'offset' => $cursor];
            }
            if ($start >= $length) {
                break;
            }
            $kind  = substr($source, $start, 2) === '{{' ? 'output' : 'tag';
            $close = $kind                      === 'output' ? '}}' : '%}';
            $end   = strpos($source, $close, $start + 2);
            if ($end === false) {
                $tokens[] = ['kind' => 'text', 'value' => substr($source, $start), 'offset' => $start];

                break;
            }
            $tokens[] = ['kind' => $kind, 'value' => trim(substr($source, $start + 2, $end - $start - 2)), 'offset' => $start];
            $cursor   = $end + 2;
        }

        return $tokens;
    }

    /**
     * Parse the stable parts of a Liquid output expression without pretending
     * to evaluate it. The IR records root objects (`product`, `section`) and
     * filter names, while the original base expression remains available for
     * asset dependency extraction.
     *
     * @return array{base: string, objects: array<int, string>, filters: array<int, string>, raw: string}
     */
    private function expression(string $value): array
    {
        $parts   = array_map('trim', explode('|', $value));
        $base    = trim((string) array_shift($parts), " \t\n\r\0\x0B-");
        $filters = [];
        foreach ($parts as $part) {
            $name = trim((string) strtok($part, ':'), " \t\n\r\0\x0B-");
            if (preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
                $filters[] = $name;
            }
        }
        $objects = [];
        // Quoted strings and numeric literals are values, not Shopify
        // objects. A root token is all we need for commerce classification.
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $base, $match)) {
            $objects[] = $match[1];
        }

        return ['base' => $base, 'objects' => $objects, 'filters' => $filters, 'raw' => $value];
    }

    /** @return array{name: string, argument: string} */
    private function tag(string $value): array
    {
        // Liquid whitespace control uses {%- and -%}; the marker is syntax,
        // not part of the tag name or its arguments.
        $parts    = preg_split('/\s+/', trim($value, " \t\n\r\0\x0B-"), 2) ?: [];
        $name     = strtolower($parts[0] ?? '');
        $argument = trim($parts[1] ?? '');
        if (in_array($name, ['render', 'include'], true)) {
            // Named render arguments can be comma-separated or whitespace
            // separated. The quoted first token is the snippet identifier.
            if (preg_match('/^[\'\"]([^\'\"]+)[\'\"]/', $argument, $match)) {
                $argument = $match[1];
            } else {
                $argument = trim((string) strtok($argument, ','), " \t\n\r\0\x0B'\"");
            }
        }

        return ['name' => $name, 'argument' => $argument];
    }

    /** @return array<int, string> */
    private function assetDependencies(array $expression): array
    {
        if (! in_array('asset_url', $expression['filters'], true)) {
            return [];
        }
        $asset = trim($expression['base'], " \t\n\r\0\x0B'\"");

        return $asset === '' ? [] : ['assets/' . $asset];
    }

    private function schemaAfter(array $tokens, int $offset): ?string
    {
        $content = '';
        $started = false;
        foreach ($tokens as $token) {
            if ($token['offset'] === $offset) {
                $started = true;

                continue;
            }
            if (! $started) {
                continue;
            }
            if ($token['kind'] === 'tag' && $this->tag($token['value'])['name'] === 'endschema') {
                return trim($content);
            }
            $content .= $token['kind'] === 'text' ? $token['value'] : ($token['kind'] === 'output' ? '{{' . $token['value'] . '}}' : '{%' . $token['value'] . '%}');
        }

        return null;
    }
}
