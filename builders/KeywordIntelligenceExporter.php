<?php

declare(strict_types=1);

class KeywordIntelligenceExporter
{
    private array $config;
    private JsonWriter $json;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->json = new JsonWriter(
            rtrim($config['output'], '/') . '/json'
        );
    }

    /**
     * @return array{map: array<string,mixed>, knowledge: array<string,mixed>}
     */
    public function export(array $knowledge, array $brain = []): array
    {
        $gscRows = $this->loadGscCsv();
        $builder = new KeywordIntelligenceBuilder();
        $map = $builder->build($knowledge, $brain, $gscRows);
        $knowledge = $builder->enrichKnowledge($knowledge, $map);

        $this->json->write('keyword_map.json', $map);

        return [
            'map' => $map,
            'knowledge' => $knowledge,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadGscCsv(): array
    {
        $configured = (string)($this->config['gsc_csv'] ?? '');
        $candidates = array_filter([
            $configured,
            AI_EXPORTER_ROOT . '/input/gsc-queries.csv',
            AI_EXPORTER_ROOT . '/input/gsc.csv',
        ]);

        $path = '';
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if ($path === '') {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if (!is_array($header) || $header === []) {
            fclose($handle);
            return [];
        }

        $header = array_map(
            static fn($h): string => trim((string)$h),
            $header
        );

        // Strip UTF-8 BOM from first column
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (!is_array($data) || $data === []) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
