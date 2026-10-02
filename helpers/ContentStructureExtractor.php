<?php

declare(strict_types=1);

class ContentStructureExtractor
{
    public function extract(?string $html): array
    {
        $html = (string)($html ?? '');

        if (trim($html) === '') {
            return $this->emptyStructure();
        }

        $headings = $this->extractHeadings($html);
        $links = $this->extractLinks($html);
        $paragraphs = $this->extractParagraphs($html);

        $paragraphCount = count($paragraphs);
        $avgParagraphLength = 0;

        if ($paragraphCount > 0) {
            $totalWords = 0;

            foreach ($paragraphs as $paragraph) {
                $totalWords += TextMetrics::persianWordCount($paragraph);
            }

            $avgParagraphLength = (int) round($totalWords / $paragraphCount);
        }

        return [
            'headings' => $headings,
            'internal_links' => $links['internal'],
            'external_links' => $links['external'],
            'faq_candidates' => $this->extractFaqCandidates($html, $headings),
            'lists_count' => $this->countTags($html, ['ul', 'ol']),
            'tables_count' => $this->countTags($html, ['table']),
            'paragraph_count' => $paragraphCount,
            'avg_paragraph_length' => $avgParagraphLength,
        ];
    }

    public function merge(array ...$structures): array
    {
        if ($structures === []) {
            return $this->emptyStructure();
        }

        $merged = $this->emptyStructure();
        $paragraphLengths = [];

        foreach ($structures as $structure) {
            $merged['headings'] = array_merge(
                $merged['headings'],
                $structure['headings'] ?? []
            );

            $merged['internal_links'] = array_merge(
                $merged['internal_links'],
                $structure['internal_links'] ?? []
            );

            $merged['external_links'] = array_merge(
                $merged['external_links'],
                $structure['external_links'] ?? []
            );

            $merged['faq_candidates'] = array_merge(
                $merged['faq_candidates'],
                $structure['faq_candidates'] ?? []
            );

            $merged['lists_count'] += (int)($structure['lists_count'] ?? 0);
            $merged['tables_count'] += (int)($structure['tables_count'] ?? 0);
            $merged['paragraph_count'] += (int)($structure['paragraph_count'] ?? 0);

            if (!empty($structure['paragraph_count'])) {
                $paragraphLengths[] = [
                    'count' => (int)$structure['paragraph_count'],
                    'avg' => (int)($structure['avg_paragraph_length'] ?? 0),
                ];
            }
        }

        $merged['internal_links'] = $this->uniqueLinks($merged['internal_links']);
        $merged['external_links'] = $this->uniqueLinks($merged['external_links']);

        $totalParagraphs = 0;
        $weightedWords = 0;

        foreach ($paragraphLengths as $item) {
            $totalParagraphs += $item['count'];
            $weightedWords += $item['count'] * $item['avg'];
        }

        $merged['avg_paragraph_length'] = $totalParagraphs > 0
            ? (int) round($weightedWords / $totalParagraphs)
            : 0;

        return $merged;
    }

    private function emptyStructure(): array
    {
        return [
            'headings' => [],
            'internal_links' => [],
            'external_links' => [],
            'faq_candidates' => [],
            'lists_count' => 0,
            'tables_count' => 0,
            'paragraph_count' => 0,
            'avg_paragraph_length' => 0,
        ];
    }

    private function extractHeadings(string $html): array
    {
        $headings = [];

        if (!preg_match_all(
            '/<h([1-6])[^>]*>(.*?)<\/h\1>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return $headings;
        }

        foreach ($matches as $match) {
            $text = ai_clean_text($match[2]);

            if ($text === '') {
                continue;
            }

            $headings[] = [
                'level' => (int)$match[1],
                'text' => $text,
            ];
        }

        return $headings;
    }

    private function extractLinks(string $html): array
    {
        $internal = [];
        $external = [];

        if (!preg_match_all(
            '/<a\s+[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [
                'internal' => $internal,
                'external' => $external,
            ];
        }

        $home = $this->homeUrl();
        $homeHost = parse_url($home, PHP_URL_HOST) ?: '';

        foreach ($matches as $match) {
            $url = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $anchor = ai_clean_text($match[2]);

            if ($url === '' || str_starts_with($url, '#') || str_starts_with(strtolower($url), 'mailto:') || str_starts_with(strtolower($url), 'tel:') || str_starts_with(strtolower($url), 'javascript:')) {
                continue;
            }

            $absolute = $this->absolutizeUrl($url, $home);
            $absolute = strtok($absolute, '#') ?: $absolute;

            $host = parse_url($absolute, PHP_URL_HOST) ?: '';

            if ($homeHost !== '' && $host !== '' && strcasecmp($host, $homeHost) === 0) {
                $targetId = 0;

                if (function_exists('url_to_postid')) {
                    $targetId = (int) url_to_postid($absolute);
                }

                $internal[] = [
                    'url' => $absolute,
                    'anchor' => $anchor,
                    'target_post_id' => $targetId,
                ];
            } else {
                $external[] = [
                    'url' => $absolute,
                    'anchor' => $anchor,
                ];
            }
        }

        return [
            'internal' => $this->uniqueLinks($internal),
            'external' => $this->uniqueLinks($external),
        ];
    }

    private function extractParagraphs(string $html): array
    {
        $paragraphs = [];

        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            foreach ($matches[1] as $paragraphHtml) {
                $text = ai_clean_text($paragraphHtml);

                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }
        }

        if ($paragraphs !== []) {
            return $paragraphs;
        }

        $plain = ai_clean_text($html);

        if ($plain === '') {
            return [];
        }

        $chunks = preg_split('/\n{2,}/u', $plain) ?: [$plain];

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);

            if ($chunk !== '') {
                $paragraphs[] = $chunk;
            }
        }

        return $paragraphs;
    }

    private function extractFaqCandidates(string $html, array $headings): array
    {
        $candidates = [];

        if (preg_match_all(
            '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $html,
            $scripts
        )) {
            foreach ($scripts[1] as $json) {
                $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

                if (!is_array($decoded)) {
                    continue;
                }

                $candidates = array_merge(
                    $candidates,
                    $this->faqFromSchema($decoded)
                );
            }
        }

        foreach ($headings as $index => $heading) {
            $text = $heading['text'] ?? '';

            if ($text === '' || !$this->looksLikeQuestion($text)) {
                continue;
            }

            $answer = '';
            $next = $headings[$index + 1]['text'] ?? null;

            if (preg_match(
                '/'.preg_quote($text, '/').'\s*(.*?)(?='.($next ? preg_quote($next, '/') : '$').')/su',
                ai_clean_text($html),
                $match
            )) {
                $answer = trim($match[1] ?? '');
                $answer = mb_substr($answer, 0, 500, 'UTF-8');
            }

            $candidates[] = [
                'question' => $text,
                'answer' => $answer,
                'source' => 'heading',
            ];
        }

        return $this->uniqueFaqs($candidates);
    }

    private function faqFromSchema(array $data): array
    {
        $items = [];

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $items = array_merge($items, $this->faqFromSchema($node));
                }
            }
        }

        $type = $data['@type'] ?? '';

        if (is_array($type)) {
            $type = implode(',', $type);
        }

        if (stripos((string)$type, 'FAQPage') !== false) {
            $entities = $data['mainEntity'] ?? [];

            if (isset($entities['@type'])) {
                $entities = [$entities];
            }

            foreach ((array)$entities as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $question = ai_clean_text((string)($entity['name'] ?? ''));
                $answer = '';

                if (isset($entity['acceptedAnswer']['text'])) {
                    $answer = ai_clean_text((string)$entity['acceptedAnswer']['text']);
                }

                if ($question !== '') {
                    $items[] = [
                        'question' => $question,
                        'answer' => mb_substr($answer, 0, 500, 'UTF-8'),
                        'source' => 'schema',
                    ];
                }
            }
        }

        return $items;
    }

    private function looksLikeQuestion(string $text): bool
    {
        if (str_contains($text, '?') || str_contains($text, '؟')) {
            return true;
        }

        return (bool) preg_match(
            '/^(چه|چرا|چگونه|چطور|آیا|کدام|کی|کجا|چند|what|why|how|when|where|which|is|are|do|does|can)\b/iu',
            $text
        );
    }

    private function countTags(string $html, array $tags): int
    {
        $count = 0;

        foreach ($tags as $tag) {
            if (preg_match_all('/<' . preg_quote($tag, '/') . '\b/i', $html, $matches)) {
                $count += count($matches[0]);
            }
        }

        return $count;
    }

    private function uniqueLinks(array $links): array
    {
        $seen = [];
        $result = [];

        foreach ($links as $link) {
            $key = ($link['url'] ?? '') . '|' . ($link['anchor'] ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $link;
        }

        return $result;
    }

    private function uniqueFaqs(array $faqs): array
    {
        $seen = [];
        $result = [];

        foreach ($faqs as $faq) {
            $key = mb_strtolower(($faq['question'] ?? '') . '|' . ($faq['source'] ?? ''), 'UTF-8');

            if ($key === '|' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $faq;
        }

        return $result;
    }

    private function homeUrl(): string
    {
        if (function_exists('home_url')) {
            return rtrim((string) home_url('/'), '/');
        }

        return '';
    }

    private function absolutizeUrl(string $url, string $home): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if ($home === '') {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($home, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            $parts = parse_url($home);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }

            return $origin . $url;
        }

        return rtrim($home, '/') . '/' . ltrim($url, '/');
    }
}
