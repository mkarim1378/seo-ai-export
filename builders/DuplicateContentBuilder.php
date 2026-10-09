<?php

declare(strict_types=1);

/**
 * Near-duplicate detection via normalized content fingerprints and title groups.
 */
class DuplicateContentBuilder
{
    private const MIN_WORDS = 40;
    private const MAX_GROUPS = 80;

    public function build(array $knowledge): array
    {
        $fingerprints = [];
        $titleGroups = [];

        foreach ([
            'posts' => 'post',
            'pages' => 'page',
            'products' => 'product',
        ] as $bucket => $type) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $status = (string)($entity['basic']['status'] ?? '');
                if ($status !== '' && $status !== 'publish') {
                    continue;
                }

                $id = (int)($entity['basic']['id'] ?? 0);
                $url = (string)($entity['basic']['url'] ?? '');
                $title = (string)($entity['basic']['title'] ?? '');
                $wordCount = (int)($entity['content']['word_count'] ?? 0);

                $body = (string)(
                    $entity['content']['content']
                    ?? $entity['content']['description']
                    ?? ''
                );
                if ($bucket === 'products') {
                    $body = trim(
                        (string)($entity['content']['short_description'] ?? '') . ' ' .
                        (string)($entity['content']['description'] ?? '')
                    );
                }

                $titleKey = TextMetrics::normalizeKeyword($title);
                if ($titleKey !== '') {
                    $titleGroups[$titleKey][] = [
                        'entity_type' => $type,
                        'entity_id' => $id,
                        'url' => $url,
                        'title' => $title,
                    ];
                }

                if ($wordCount < self::MIN_WORDS && mb_strlen($body, 'UTF-8') < 200) {
                    continue;
                }

                $fp = $this->fingerprint($body);
                if ($fp === '') {
                    continue;
                }

                $fingerprints[$fp][] = [
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'url' => $url,
                    'title' => $title,
                    'word_count' => $wordCount,
                ];
            }
        }

        $contentDupes = [];
        foreach ($fingerprints as $fp => $group) {
            if (count($group) < 2) {
                continue;
            }
            $contentDupes[] = [
                'fingerprint' => $fp,
                'count' => count($group),
                'entities' => $group,
            ];
        }

        $titleDupes = [];
        foreach ($titleGroups as $key => $group) {
            if (count($group) < 2) {
                continue;
            }
            // Same title across different entity types / ids
            $titleDupes[] = [
                'normalized_title' => $key,
                'count' => count($group),
                'entities' => $group,
            ];
        }

        usort($contentDupes, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        usort($titleDupes, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'Heuristic near-duplicates via normalized text fingerprint (not plagiarism score). Thin pages under ~' . self::MIN_WORDS . ' words are skipped for body fingerprints.',
            'summary' => [
                'content_duplicate_groups' => count($contentDupes),
                'title_duplicate_groups' => count($titleDupes),
                'entities_in_content_dupes' => array_sum(array_column($contentDupes, 'count')),
            ],
            'content_duplicates' => array_slice($contentDupes, 0, self::MAX_GROUPS),
            'title_duplicates' => array_slice($titleDupes, 0, self::MAX_GROUPS),
        ];
    }

    private function fingerprint(string $text): string
    {
        $normalized = TextMetrics::normalizeKeyword(ai_clean_text($text));
        if ($normalized === '') {
            return '';
        }

        // Collapse to a stable sample: first 800 chars of normalized text
        $sample = mb_substr($normalized, 0, 800, 'UTF-8');

        return md5($sample);
    }
}
