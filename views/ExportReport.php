<?php

declare(strict_types=1);

class ExportReport
{
    public function render(array $report): void
    {
        if (PHP_SAPI === 'cli') {
            $this->renderCli($report);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderHtml($report);
    }

    private function renderCli(array $report): void
    {
        if (!empty($report['error'])) {
            echo "ERROR\n";
            echo ($report['error']['message'] ?? '') . "\n";
            echo ($report['error']['file'] ?? '') . "\n";
            echo 'Line ' . ($report['error']['line'] ?? '') . "\n";
            return;
        }

        echo "SEO AI Export complete\n";
        echo 'Site: ' . ($report['site_name'] ?? '') . "\n";
        echo 'Time: ' . ($report['duration'] ?? 0) . " sec\n\n";

        foreach ($report['counts'] ?? [] as $label => $count) {
            echo sprintf("%-12s %s\n", ucfirst($label) . ':', $count);
        }

        $audit = $report['audit'] ?? [];
        echo "\nSEO Audit: " . ($audit['total'] ?? 0) . " findings\n";
        echo '  critical: ' . ($audit['critical'] ?? 0) . "\n";
        echo '  warning: ' . ($audit['warning'] ?? 0) . "\n";
        echo '  opportunity: ' . ($audit['opportunity'] ?? 0) . "\n";
    }

    private function renderHtml(array $report): string
    {
        $cssPath = AI_EXPORTER_ROOT . '/assets/report.css';
        $css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';

        $hasError = !empty($report['error']);
        $title = $hasError ? 'Export failed' : 'Export complete';
        $siteName = $this->e((string)($report['site_name'] ?? 'Site'));
        $siteUrl = $this->e((string)($report['site_url'] ?? '#'));
        $generatedAt = $this->e((string)($report['generated_at'] ?? ''));
        $duration = $this->e((string)($report['duration'] ?? '0'));

        $countsHtml = '';
        foreach ($report['counts'] ?? [] as $label => $count) {
            $countsHtml .= '<div class="stat">'
                . '<span class="stat-value">' . $this->e((string)$count) . '</span>'
                . '<span class="stat-label">' . $this->e((string)$label) . '</span>'
                . '</div>';
        }

        $audit = $report['audit'] ?? [];
        $link = $report['link_analysis'] ?? [];

        $filesHtml = '';
        foreach ($report['files'] ?? [] as $file) {
            $name = $this->e((string)($file['name'] ?? ''));
            $href = $this->e((string)($file['href'] ?? '#'));
            $desc = $this->e((string)($file['description'] ?? ''));
            $filesHtml .= '<a class="file-row" href="' . $href . '" target="_blank" rel="noopener">'
                . '<span class="file-name">' . $name . '</span>'
                . '<span class="file-desc">' . $desc . '</span>'
                . '<span class="file-open">Open</span>'
                . '</a>';
        }

        $body = '';

        if ($hasError) {
            $error = $report['error'];
            $body = '<section class="panel error-panel">'
                . '<h2>Something went wrong</h2>'
                . '<p class="error-message">' . $this->e((string)($error['message'] ?? '')) . '</p>'
                . '<p class="error-meta">' . $this->e((string)($error['file'] ?? ''))
                . ' · line ' . $this->e((string)($error['line'] ?? '')) . '</p>'
                . '</section>';
        } else {
            $body = <<<HTML
<section class="panel">
  <h2>Exported entities</h2>
  <div class="stats">{$countsHtml}</div>
</section>

<section class="split">
  <div class="panel">
    <h2>SEO audit</h2>
    <div class="severity-row">
      <div class="severity critical">
        <strong>{$this->e((string)($audit['critical'] ?? 0))}</strong>
        <span>Critical</span>
      </div>
      <div class="severity warning">
        <strong>{$this->e((string)($audit['warning'] ?? 0))}</strong>
        <span>Warning</span>
      </div>
      <div class="severity opportunity">
        <strong>{$this->e((string)($audit['opportunity'] ?? 0))}</strong>
        <span>Opportunity</span>
      </div>
    </div>
    <p class="muted">Total findings: {$this->e((string)($audit['total'] ?? 0))}</p>
  </div>

  <div class="panel">
    <h2>Link graph</h2>
    <dl class="kv">
      <div><dt>Nodes</dt><dd>{$this->e((string)($link['node_count'] ?? 0))}</dd></div>
      <div><dt>Orphans</dt><dd>{$this->e((string)($link['orphan_count'] ?? 0))}</dd></div>
      <div><dt>Weak hubs</dt><dd>{$this->e((string)($link['weak_hub_count'] ?? 0))}</dd></div>
      <div><dt>Dead links</dt><dd>{$this->e((string)($link['dead_link_count'] ?? 0))}</dd></div>
    </dl>
  </div>
</section>

<section class="panel">
  <h2>Output files</h2>
  <div class="files">{$filesHtml}</div>
</section>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$this->e($title)} · SEO AI Export</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>{$css}</style>
</head>
<body>
  <main class="page">
    <header class="hero">
      <p class="brand">SEO AI Export</p>
      <h1>{$this->e($title)}</h1>
      <p class="lede">
        <a href="{$siteUrl}">{$siteName}</a>
        <span class="dot">·</span>
        {$generatedAt}
        <span class="dot">·</span>
        {$duration}s
      </p>
    </header>
    {$body}
    <footer class="foot">
      WordPress / WooCommerce site intelligence for SEO agents
    </footer>
  </main>
</body>
</html>
HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
