<?php

declare(strict_types=1);

/**
 * Compare two seo_audit snapshots for before/after workflows.
 */
class AuditDiffBuilder
{
    public function build(?array $previous, array $current): array
    {
        $prevFindings = is_array($previous) ? ($previous['findings'] ?? []) : [];
        $currFindings = $current['findings'] ?? [];

        $prevById = [];
        foreach ($prevFindings as $f) {
            $id = (string)($f['id'] ?? '');
            if ($id !== '') {
                $prevById[$id] = $f;
            }
        }

        $currById = [];
        foreach ($currFindings as $f) {
            $id = (string)($f['id'] ?? '');
            if ($id !== '') {
                $currById[$id] = $f;
            }
        }

        $added = [];
        $resolved = [];
        $persistent = [];
        $severityChanged = [];

        foreach ($currById as $id => $finding) {
            if (!isset($prevById[$id])) {
                $added[] = $this->slim($finding);
                continue;
            }
            $prev = $prevById[$id];
            $prevSev = (string)($prev['severity'] ?? '');
            $currSev = (string)($finding['severity'] ?? '');
            if ($prevSev !== $currSev) {
                $severityChanged[] = [
                    'id' => $id,
                    'from' => $prevSev,
                    'to' => $currSev,
                    'type' => $finding['type'] ?? '',
                    'url' => $finding['url'] ?? '',
                    'title' => $finding['title'] ?? '',
                ];
            }
            $persistent[] = $this->slim($finding);
        }

        foreach ($prevById as $id => $finding) {
            if (!isset($currById[$id])) {
                $resolved[] = $this->slim($finding);
            }
        }

        $prevSummary = is_array($previous) ? ($previous['summary'] ?? []) : [];
        $currSummary = $current['summary'] ?? [];

        return [
            'version' => '1.0',
            'generated_at' => function_exists('current_time')
                ? current_time('mysql')
                : date('Y-m-d H:i:s'),
            'note' => 'Diff of seo_audit findings by finding.id. Requires a previous seo_audit.json from an earlier export.',
            'has_previous' => is_array($previous) && $prevFindings !== [],
            'previous_generated_at' => is_array($previous) ? ($previous['generated_at'] ?? null) : null,
            'current_generated_at' => $current['generated_at'] ?? null,
            'summary' => [
                'previous_total' => (int)($prevSummary['total'] ?? count($prevFindings)),
                'current_total' => (int)($currSummary['total'] ?? count($currFindings)),
                'added' => count($added),
                'resolved' => count($resolved),
                'persistent' => count($persistent),
                'severity_changed' => count($severityChanged),
                'net_change' => (int)($currSummary['total'] ?? count($currFindings))
                    - (int)($prevSummary['total'] ?? count($prevFindings)),
                'severity_delta' => [
                    'critical' => (int)($currSummary['critical'] ?? 0) - (int)($prevSummary['critical'] ?? 0),
                    'warning' => (int)($currSummary['warning'] ?? 0) - (int)($prevSummary['warning'] ?? 0),
                    'opportunity' => (int)($currSummary['opportunity'] ?? 0) - (int)($prevSummary['opportunity'] ?? 0),
                ],
            ],
            'added' => array_slice($added, 0, 200),
            'resolved' => array_slice($resolved, 0, 200),
            'severity_changed' => array_slice($severityChanged, 0, 100),
            'persistent_sample' => array_slice($persistent, 0, 50),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function slim(array $finding): array
    {
        return [
            'id' => $finding['id'] ?? '',
            'severity' => $finding['severity'] ?? '',
            'type' => $finding['type'] ?? '',
            'entity_type' => $finding['entity_type'] ?? '',
            'entity_id' => $finding['entity_id'] ?? 0,
            'url' => $finding['url'] ?? '',
            'title' => $finding['title'] ?? '',
            'ai_action' => $finding['ai_action'] ?? '',
            'recommendation' => $finding['recommendation'] ?? '',
        ];
    }
}
