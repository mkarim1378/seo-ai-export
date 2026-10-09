<?php

declare(strict_types=1);

/**
 * Extract @type values from JSON-LD script tags in HTML.
 * Does not fetch live frontend HTML — only the HTML string provided (raw or rendered).
 */
class SchemaExtractor
{
    /**
     * @return array{
     *   types: list<string>,
     *   blocks: list<array<string,mixed>>,
     *   count: int
     * }
     */
    public function extractFromHtml(?string $html): array
    {
        $html = (string)($html ?? '');
        if (trim($html) === '') {
            return ['types' => [], 'blocks' => [], 'count' => 0];
        }

        if (!preg_match_all(
            '/<script[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $html,
            $matches
        )) {
            return ['types' => [], 'blocks' => [], 'count' => 0];
        }

        $types = [];
        $blocks = [];

        foreach ($matches[1] as $json) {
            $json = html_entity_decode(trim((string)$json), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }

            $found = $this->collectTypes($decoded);
            if ($found === []) {
                continue;
            }

            $types = array_merge($types, $found);
            $blocks[] = [
                'types' => $found,
                'has_graph' => isset($decoded['@graph']),
            ];
        }

        $types = array_values(array_unique(array_filter(array_map(
            static fn($t): string => self::normalizeType((string)$t),
            $types
        ))));

        return [
            'types' => $types,
            'blocks' => $blocks,
            'count' => count($blocks),
        ];
    }

    /**
     * Compare plugin-claimed schema types vs detected JSON-LD types.
     *
     * @param list<string> $claimed
     * @param list<string> $detected
     * @return array{
     *   claimed: list<string>,
     *   detected: list<string>,
     *   missing_in_content: list<string>,
     *   schema_claimed_only: bool,
     *   note: string
     * }
     */
    public function compare(array $claimed, array $detected): array
    {
        $claimedN = array_values(array_unique(array_filter(array_map(
            [self::class, 'normalizeType'],
            $claimed
        ))));
        $detectedN = array_values(array_unique(array_filter(array_map(
            [self::class, 'normalizeType'],
            $detected
        ))));

        $missing = [];
        foreach ($claimedN as $type) {
            if (!$this->typeCovered($type, $detectedN)) {
                $missing[] = $type;
            }
        }

        return [
            'claimed' => $claimedN,
            'detected' => $detectedN,
            'missing_in_content' => $missing,
            'schema_claimed_only' => $claimedN !== [] && $missing !== [] && $detectedN === [],
            'note' => 'Detection is limited to JSON-LD inside stored/rendered post HTML — not a live SERP/rich-result crawl. WooCommerce/theme schemas injected only on the frontend may be absent here.',
        ];
    }

    public static function normalizeType(string $type): string
    {
        $type = trim($type);
        if ($type === '') {
            return '';
        }

        // Strip schema.org URL prefix
        if (preg_match('#schema\.org/([^/#?\s]+)#i', $type, $m)) {
            $type = $m[1];
        }

        return strtolower($type);
    }

    /**
     * @param list<string> $detected
     */
    private function typeCovered(string $claimed, array $detected): bool
    {
        if (in_array($claimed, $detected, true)) {
            return true;
        }

        // Soft aliases
        $aliases = [
            'faqpage' => ['faq', 'faqpage'],
            'faq' => ['faq', 'faqpage'],
            'product' => ['product'],
            'article' => ['article', 'blogposting', 'newsarticle'],
            'blogposting' => ['article', 'blogposting'],
            'webpage' => ['webpage', 'webpage'],
        ];

        $group = $aliases[$claimed] ?? [$claimed];
        foreach ($detected as $d) {
            if (in_array($d, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed>|list<mixed> $node
     * @return list<string>
     */
    private function collectTypes(array $node): array
    {
        $types = [];

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $item) {
                if (is_array($item)) {
                    $types = array_merge($types, $this->collectTypes($item));
                }
            }
        }

        if (isset($node['@type'])) {
            $t = $node['@type'];
            if (is_array($t)) {
                foreach ($t as $one) {
                    if (is_string($one) && $one !== '') {
                        $types[] = $one;
                    }
                }
            } elseif (is_string($t) && $t !== '') {
                $types[] = $t;
            }
        }

        // Nested typed objects (limited depth via recursion on array values)
        foreach ($node as $key => $value) {
            if ($key === '@graph' || $key === '@type') {
                continue;
            }
            if (is_array($value)) {
                // list of objects or single object
                if ($this->isAssoc($value) && isset($value['@type'])) {
                    $types = array_merge($types, $this->collectTypes($value));
                } else {
                    foreach ($value as $child) {
                        if (is_array($child) && isset($child['@type'])) {
                            $types = array_merge($types, $this->collectTypes($child));
                        }
                    }
                }
            }
        }

        return $types;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
