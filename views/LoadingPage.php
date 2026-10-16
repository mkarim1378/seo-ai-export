<?php

declare(strict_types=1);

/**
 * صفحه‌ای که فوراً (بدون لود وردپرس) نمایش داده می‌شود، Export را در پس‌زمینه
 * شروع می‌کند و پیشرفت را از ?action=status نشان می‌دهد.
 */
class LoadingPage
{
    public function render(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $this->html();
    }

    private function html(): string
    {
        $cssPath = AI_EXPORTER_ROOT . '/assets/report.css';
        $css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Starting export · SEO AI Export</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>{$css}</style>
</head>
<body>
  <main class="page loader" data-state="starting">
    <header class="hero">
      <p class="brand">SEO AI Export</p>
      <h1><span class="spinner" aria-hidden="true"></span><span id="title">Starting export…</span></h1>
      <p class="lede" id="lede">
        Large sites can take several minutes. Keep this tab open to watch progress —
        closing it does not stop the export on the server.
      </p>
    </header>

    <section class="panel" aria-live="polite">
      <div class="progress-head">
        <span id="activity">Connecting to the server…</span>
        <span class="progress-meta"><span id="percent">0%</span> · <span id="elapsed">0:00</span></span>
      </div>
      <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="bar">
        <div class="progress-fill" id="fill"></div>
      </div>
      <p class="muted progress-sub" id="sub">&nbsp;</p>
    </section>

    <section class="panel error-panel" id="error" hidden>
      <h2>The export stopped</h2>
      <p class="error-message" id="error-message"></p>
      <p class="error-meta" id="error-hint">
        On large sites, try running it from SSH (<code>php index.php</code>), or temporarily
        turn off heavy parts in <code>config.php</code>.
      </p>
      <div class="actions">
        <button type="button" class="btn" id="retry">Try again</button>
        <a class="btn btn-ghost" id="error-report" href="index.php?action=report" hidden>View error details</a>
      </div>
    </section>

    <section class="panel">
      <h2>Steps</h2>
      <ol class="steps" id="steps">
        <li class="step" data-state="running"><span class="step-icon"></span><span class="step-label">Preparing</span><span class="step-detail"></span></li>
      </ol>
    </section>

    <noscript>
      <section class="panel">
        <p>JavaScript is disabled. <a href="index.php?sync=1">Run the export without the progress page</a>
        (the page will stay blank until it finishes).</p>
      </section>
    </noscript>

    <footer class="foot">
      WordPress / WooCommerce site intelligence for SEO agents
    </footer>
  </main>

<script>
(function () {
  'use strict';

  var WEIGHTS = {
    wordpress: 4, products: 18, categories: 3, posts: 10, pages: 5, media: 8,
    site_brain: 12, keyword_map: 8, knowledge: 3, redirect_map: 2, hreflang: 2,
    sitemap_coverage: 3, media_seo: 2, content_duplicates: 5, seo_audit: 5,
    audit_diff: 1, ai_context: 3, ai_pack: 4, manifest: 2
  };
  var POLL_MS = 1000;
  var QUIET_AFTER = 45;

  var base = location.href.split('#')[0].split('?')[0];
  var runId = Math.random().toString(36).slice(2) + Date.now().toString(36);
  var adopted = false;
  var finished = false;
  var lastStatus = null;
  var clockOffset = 0;
  var failures = 0;
  var startedClient = Date.now() / 1000;
  var pollTimer = null;

  var el = function (id) { return document.getElementById(id); };
  var root = document.querySelector('.loader');

  function url(action) {
    return base + '?action=' + action + '&_=' + Date.now();
  }

  function fmtTime(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var mm = (h ? String(m).padStart(2, '0') : String(m));
    return (h ? h + ':' : '') + mm + ':' + String(s).padStart(2, '0');
  }

  function fmtNum(n) {
    try { return Number(n).toLocaleString('en-US'); } catch (e) { return String(n); }
  }

  function serverNow() {
    return Date.now() / 1000 + clockOffset;
  }

  function start() {
    finished = false;
    adopted = false;
    failures = 0;
    lastStatus = null;
    runId = Math.random().toString(36).slice(2) + Date.now().toString(36);
    startedClient = Date.now() / 1000;
    root.dataset.state = 'starting';
    el('error').hidden = true;
    el('lede').hidden = false;
    setTitle('Starting export…', 'Starting export');
    el('activity').textContent = 'Connecting to the server…';

    var body = new URLSearchParams();
    body.set('run_id', runId);

    fetch(url('run'), {
      method: 'POST',
      body: body,
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (res) {
      if (res.status === 409) {
        return res.json().then(function (data) {
          // اجرای دیگری (مثلاً از تب قبلی) در جریان است؛ به همان وصل شو.
          if (data && data.run_id) { runId = data.run_id; }
          adopted = true;
          el('lede').textContent = 'An export was already running, so this page is following it instead of starting a new one.';
        });
      }
      if (!res.ok) {
        return res.text().then(function (t) { throw new Error('HTTP ' + res.status + (t ? ': ' + t.slice(0, 200) : '')); });
      }
    }).catch(function (err) {
      // پاسخ run ممکن است به‌خاطر timeout پراکسی قطع شود در حالی که سرور هنوز کار می‌کند؛
      // تصمیم نهایی با status است. فقط اگر اجرا اصلاً شروع نشده باشد خطا نشان بده.
      if (!finished && (!lastStatus || lastStatus.run_id !== runId)) {
        setTimeout(function () {
          if (!finished && (!lastStatus || lastStatus.run_id !== runId)) {
            showError('Could not start the export. ' + (err && err.message ? err.message : ''), false);
          }
        }, 4000);
      }
    });

    schedule(300);
  }

  function schedule(ms) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
  }

  function poll() {
    if (finished) { return; }
    fetch(url('status'), { cache: 'no-store', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.json();
      })
      .then(function (data) {
        failures = 0;
        el('sub').classList.remove('warn');
        handle(data);
        if (!finished) { schedule(POLL_MS); }
      })
      .catch(function () {
        failures++;
        var sub = el('sub');
        sub.classList.add('warn');
        sub.textContent = 'Connection hiccup — retrying… (the export keeps running on the server)';
        if (!finished) { schedule(Math.min(10000, POLL_MS * Math.pow(1.6, failures))); }
      });
  }

  function handle(data) {
    if (!data || typeof data !== 'object') { return; }
    if (typeof data.now === 'number') { clockOffset = data.now - Date.now() / 1000; }

    if (data.run_id !== runId) {
      // هنوز وضعیت اجرای قبلی است؛ اگر طولانی شد و اجرای جدید شروع نشد، صبر کن.
      if (!adopted && data.state === 'running' && Date.now() / 1000 - startedClient > 3) {
        runId = data.run_id;
        adopted = true;
      } else {
        return;
      }
    }

    lastStatus = data;
    renderSteps(data.steps || []);
    renderProgress(data);

    if (data.state === 'done') {
      finished = true;
      root.dataset.state = 'done';
      setTitle('Export complete — opening report…', '✓ Export complete');
      el('activity').textContent = 'All steps finished.';
      setPercent(100);
      setTimeout(function () { location.replace(base + '?action=report'); }, 700);
    } else if (data.state === 'failed') {
      finished = true;
      showError(data.error || 'Unknown error.', !!data.has_report);
    }
  }

  function stepWeight(key) { return WEIGHTS[key] || 3; }

  function renderProgress(data) {
    var steps = data.steps || [];
    var total = 0, done = 0, current = null;
    steps.forEach(function (s) {
      var w = stepWeight(s.key);
      total += w;
      if (s.state === 'done' || s.state === 'skipped' || s.state === 'failed') { done += w; }
      else if (s.state === 'running') {
        current = s;
        var c = data.current;
        if (c && c.key === s.key && c.total) { done += w * Math.min(1, c.done / c.total); }
      }
    });

    var pct = total ? Math.min(99, Math.floor(done / total * 100)) : 0;
    if (data.state === 'running') {
      setPercent(pct);
      root.dataset.state = 'running';
      setTitle('Exporting your site…', pct + '% · Exporting');
    }

    var c = data.current;
    if (current) {
      var text = current.label;
      if (c && c.key === current.key && c.total) {
        text += ' — ' + fmtNum(c.done) + ' / ' + fmtNum(c.total);
      }
      el('activity').textContent = text;
    }
  }

  function setPercent(pct) {
    el('percent').textContent = pct + '%';
    el('fill').style.width = pct + '%';
    el('bar').setAttribute('aria-valuenow', String(pct));
  }

  function setTitle(heading, tab) {
    el('title').textContent = heading;
    document.title = tab + ' · SEO AI Export';
  }

  function renderSteps(steps) {
    var list = el('steps');
    var now = serverNow();
    var html = '';
    steps.forEach(function (s) {
      var detail = s.detail || '';
      if (s.state === 'running' && s.started_at) {
        detail = fmtTime(now - s.started_at);
      } else if (s.state === 'done' && s.started_at && s.finished_at) {
        var d = s.finished_at - s.started_at;
        detail = (detail ? detail + ' · ' : '') + (d < 1 ? '<1s' : fmtTime(d));
      }
      html += '<li class="step" data-key="' + esc(s.key) + '" data-state="' + esc(s.state) + '">'
        + '<span class="step-icon" aria-hidden="true"></span>'
        + '<span class="step-label">' + esc(s.label) + '</span>'
        + '<span class="step-detail">' + esc(detail) + '</span></li>';
    });
    if (html) { list.innerHTML = html; }
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function showError(message, hasReport) {
    finished = true;
    clearTimeout(pollTimer);
    root.dataset.state = 'failed';
    setTitle('Export failed', '✕ Export failed');
    el('error-message').textContent = message;
    el('error-report').hidden = !hasReport;
    el('error').hidden = false;
    el('lede').hidden = true;
    el('activity').textContent = 'Stopped.';
  }

  // تایمر و هشدار «ساکت بودن» بین pollها هر ثانیه به‌روز می‌شود.
  setInterval(function () {
    if (finished) { return; }
    var s = lastStatus;
    var startedAt = s && s.run_id === runId && s.started_at ? s.started_at : null;
    el('elapsed').textContent = fmtTime(startedAt ? serverNow() - startedAt : Date.now() / 1000 - startedClient);

    if (s && s.run_id === runId && s.state === 'running') {
      var running = document.querySelector('.step[data-state="running"] .step-detail');
      var step = (s.steps || []).filter(function (x) { return x.state === 'running'; })[0];
      if (running && step && step.started_at) { running.textContent = fmtTime(serverNow() - step.started_at); }

      if (failures === 0) {
        var quiet = serverNow() - (s.updated_at || serverNow());
        el('sub').textContent = quiet > QUIET_AFTER
          ? 'Still working on this step (no update for ' + fmtTime(quiet) + '). Heavy steps on big sites can be quiet for a while — the server is still busy.'
          : ' ';
      }
    }
  }, 1000);

  el('retry').addEventListener('click', start);
  start();
})();
</script>
</body>
</html>
HTML;
    }
}
