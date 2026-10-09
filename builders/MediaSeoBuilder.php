<?php

declare(strict_types=1);

/**
 * Site-wide media SEO heuristics from exported media library.
 */
class MediaSeoBuilder
{
    private const OVERSIZE_BYTES = 512000; // 500 KB
    private const HUGE_WIDTH = 2500;
    private const MAX_LIST = 80;

    public function build(array $knowledge): array
    {
        $missingAlt = [];
        $oversized = [];
        $hugeDimensions = [];
        $emptyTitle = [];
        $imageCount = 0;
        $totalBytes = 0;

        foreach ($knowledge['media'] ?? [] as $media) {
            $mime = (string)($media['basic']['mime_type'] ?? '');
            if ($mime !== '' && !str_starts_with($mime, 'image/')) {
                continue;
            }

            $imageCount++;
            $id = (int)($media['basic']['id'] ?? 0);
            $url = (string)($media['basic']['url'] ?? '');
            $title = trim((string)($media['basic']['title'] ?? ''));
            $alt = trim((string)($media['image']['alt'] ?? ''));
            $width = (int)($media['image']['width'] ?? 0);
            $height = (int)($media['image']['height'] ?? 0);
            $filesize = (int)($media['image']['filesize'] ?? 0);
            $totalBytes += max(0, $filesize);

            $row = [
                'id' => $id,
                'url' => $url,
                'title' => $title,
                'parent_id' => (int)($media['parent']['id'] ?? 0),
                'width' => $width,
                'height' => $height,
                'filesize' => $filesize,
            ];

            if ($alt === '') {
                $missingAlt[] = $row;
            }
            if ($filesize >= self::OVERSIZE_BYTES) {
                $oversized[] = $row + ['threshold_bytes' => self::OVERSIZE_BYTES];
            }
            if ($width >= self::HUGE_WIDTH || $height >= self::HUGE_WIDTH) {
                $hugeDimensions[] = $row;
            }
            if ($title === '' || preg_match('/^(IMG_|DSC_|image|untitled)/i', $title)) {
                $emptyTitle[] = $row;
            }
        }

        // Also count in-content missing alts from structures
        $contentMissingAlt = 0;
        foreach (['posts', 'pages', 'products'] as $bucket) {
            foreach ($knowledge[$bucket] ?? [] as $entity) {
                $contentMissingAlt += (int)($entity['structure']['images_missing_alt'] ?? 0);
            }
        }

        usort($oversized, static fn(array $a, array $b): int => ($b['filesize'] ?? 0) <=> ($a['filesize'] ?? 0));

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'Library-level media heuristics. Oversized threshold is ' . self::OVERSIZE_BYTES . ' bytes (~500KB).',
            'thresholds' => [
                'oversize_bytes' => self::OVERSIZE_BYTES,
                'huge_dimension_px' => self::HUGE_WIDTH,
            ],
            'summary' => [
                'image_count' => $imageCount,
                'total_bytes' => $totalBytes,
                'missing_alt_count' => count($missingAlt),
                'oversized_count' => count($oversized),
                'huge_dimension_count' => count($hugeDimensions),
                'weak_title_count' => count($emptyTitle),
                'in_content_images_missing_alt' => $contentMissingAlt,
            ],
            'missing_alt' => array_slice($missingAlt, 0, self::MAX_LIST),
            'oversized' => array_slice($oversized, 0, self::MAX_LIST),
            'huge_dimensions' => array_slice($hugeDimensions, 0, self::MAX_LIST),
            'weak_titles' => array_slice($emptyTitle, 0, self::MAX_LIST),
        ];
    }
}
