<?php

declare(strict_types=1);

class AiPackExporter
{
    private array $config;
    private JsonWriter $json;
    private string $packDirectory;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->packDirectory = rtrim($config['output'], '/') . '/ai-pack';

        $this->json = new JsonWriter($this->packDirectory);
    }

    /**
     * @return array{paths: array<string, string>, manifest: array, file_count: int}
     */
    public function export(
        array $knowledge,
        array $brain,
        array $audit,
        array $aiContext,
        array $keywordMap,
        array $siteProfile,
        array $redirectMap,
        array $hreflang,
        array $sitemapCoverage,
        array $mediaSeo,
        array $contentDuplicates,
        array $auditDiff
    ): array {
        $pack = (new AiPackBuilder())->build(
            $knowledge,
            $brain,
            $audit,
            $aiContext,
            $keywordMap,
            $siteProfile,
            $redirectMap,
            $hreflang,
            $sitemapCoverage,
            $mediaSeo,
            $contentDuplicates,
            $auditDiff,
            isset($this->config['version']) ? (string)$this->config['version'] : null
        );

        $this->pruneStaleFiles(array_keys($pack['files']));

        foreach ($pack['files'] as $filename => $data) {
            // Compact JSON for smaller uploads / fewer tokens from whitespace.
            $this->json->write($filename, is_array($data) ? $data : [], false);
        }

        return [
            'paths' => $pack['paths'],
            'manifest' => $pack['manifest'],
            'file_count' => count($pack['files']),
        ];
    }

    /**
     * Remove obsolete pack files (e.g. monolithic knowledge.json from older exports).
     *
     * @param list<string> $keepFilenames
     */
    private function pruneStaleFiles(array $keepFilenames): void
    {
        if (!is_dir($this->packDirectory)) {
            return;
        }

        $keep = array_fill_keys($keepFilenames, true);
        foreach (glob($this->packDirectory . '/*.json') ?: [] as $path) {
            $basename = basename($path);
            if (!isset($keep[$basename])) {
                @unlink($path);
            }
        }
    }
}
