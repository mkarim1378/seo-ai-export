<?php

declare(strict_types=1);

class AuditDiffExporter
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

    public function export(?array $previous, array $current): array
    {
        $diff = (new AuditDiffBuilder())->build($previous, $current);
        $this->json->write('audit_diff.json', $diff);

        return $diff;
    }

    /**
     * Load previous seo_audit.json if present, and archive a copy under previous/.
     */
    public function loadPreviousAudit(): ?array
    {
        $path = rtrim($this->config['output'], '/') . '/json/seo_audit.json';
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $prevDir = rtrim($this->config['output'], '/') . '/json/previous';
        if (!is_dir($prevDir)) {
            wp_mkdir_p($prevDir);
            if (!is_dir($prevDir)) {
                @mkdir($prevDir, 0755, true);
            }
        }
        if (is_dir($prevDir)) {
            @copy($path, $prevDir . '/seo_audit.json');
            $manifestPath = rtrim($this->config['output'], '/') . '/json/manifest.json';
            if (is_readable($manifestPath)) {
                @copy($manifestPath, $prevDir . '/manifest.json');
            }
        }

        return $data;
    }
}
