<?php

declare(strict_types=1);

/**
 * وضعیت اجرای Export برای صفحه لودینگ مرورگر.
 *
 * Runner در output/.run/progress.json می‌نویسد و صفحه لودینگ آن را poll می‌کند.
 * قفل (flock) روی run.lock تا پایان پروسه نگه داشته می‌شود؛ اگر پروسه بمیرد
 * (timeout / memory) قفل آزاد می‌شود و status آن را failed گزارش می‌کند.
 *
 * این کلاس به وردپرس وابسته نیست تا status/report بدون wp-load سریع جواب بدهند.
 */
class ExportProgress
{
    private const TICK_INTERVAL = 0.4;

    private static ?self $active = null;

    private string $dir;

    /** @var resource|null */
    private $lock = null;

    private array $state = [];

    private float $lastWrite = 0.0;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    public static function forRoot(string $root): self
    {
        return new self($root . '/output/.run');
    }

    /**
     * قفل اجرا را می‌گیرد؛ اگر اجرای دیگری فعال باشد false.
     */
    public function acquire(): bool
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }

        $handle = @fopen($this->dir . '/run.lock', 'c');
        if ($handle === false) {
            return true; // بدون قفل هم اجرا کن؛ فقط محافظت از اجرای هم‌زمان نداریم
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lock = $handle;
        return true;
    }

    public function isLocked(): bool
    {
        $path = $this->dir . '/run.lock';
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /**
     * @param array<string, string> $steps key => label
     */
    public function start(string $runId, array $steps): void
    {
        $now = microtime(true);
        $rows = [];
        foreach ($steps as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'state' => 'pending',
                'detail' => null,
                'started_at' => null,
                'finished_at' => null,
            ];
        }

        $this->state = [
            'state' => 'running',
            'run_id' => $runId,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
            'steps' => $rows,
            'current' => null,
            'error' => null,
        ];

        @unlink($this->reportPath());
        self::$active = $this;
        $this->write();
    }

    /**
     * تعریف یک مرحله که در start نبود (مثلاً بارگذاری وردپرس قبل از config).
     */
    public function begin(string $key, ?string $label = null): void
    {
        $now = microtime(true);
        $index = $this->index($key);
        if ($index === null) {
            $this->state['steps'][] = [
                'key' => $key,
                'label' => $label ?? $key,
                'state' => 'pending',
                'detail' => null,
                'started_at' => null,
                'finished_at' => null,
            ];
            $index = count($this->state['steps']) - 1;
        }

        $this->state['steps'][$index]['state'] = 'running';
        $this->state['steps'][$index]['started_at'] = $now;
        $this->state['current'] = ['key' => $key, 'done' => null, 'total' => null];
        $this->write();
    }

    public function done(string $key, ?string $detail = null): void
    {
        $this->finishStep($key, 'done', $detail);
    }

    public function skip(string $key, string $detail = 'disabled in config'): void
    {
        $this->finishStep($key, 'skipped', $detail);
    }

    /**
     * پیشرفت داخل مرحله جاری (مثلاً ۳۱۲ از ۱۲۴۰ محصول). بی‌اثر وقتی runner فعال نیست.
     */
    public static function tick(int $done, int $total): void
    {
        $self = self::$active;
        if ($self === null || $self->state['current'] === null) {
            return;
        }

        $self->state['current']['done'] = $done;
        $self->state['current']['total'] = $total;

        $now = microtime(true);
        if ($done >= $total || $now - $self->lastWrite >= self::TICK_INTERVAL) {
            $self->write();
        }
    }

    public function complete(string $reportHtml): void
    {
        @file_put_contents($this->reportPath(), $reportHtml, LOCK_EX);

        $this->state['state'] = 'done';
        $this->state['finished_at'] = microtime(true);
        $this->state['current'] = null;
        $this->write();
        $this->release();
    }

    public function fail(string $message, string $reportHtml = ''): void
    {
        if ($reportHtml !== '') {
            @file_put_contents($this->reportPath(), $reportHtml, LOCK_EX);
        }

        $current = $this->state['current']['key'] ?? null;
        if ($current !== null) {
            $this->finishStep($current, 'failed', null, false);
        }

        $this->state['state'] = 'failed';
        $this->state['error'] = $message;
        $this->state['finished_at'] = microtime(true);
        $this->state['current'] = null;
        $this->write();
        $this->release();
    }

    public function isRunning(): bool
    {
        return ($this->state['state'] ?? null) === 'running';
    }

    /**
     * وضعیت برای endpoint status؛ اجرای مرده (قفل آزاد ولی running) را failed می‌کند.
     */
    public function read(): array
    {
        $raw = @file_get_contents($this->dir . '/progress.json');
        $state = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($state)) {
            return ['state' => 'idle', 'now' => microtime(true)];
        }

        if (($state['state'] ?? null) === 'running' && !$this->isLocked()) {
            $state['state'] = 'failed';
            $state['error'] = $state['error']
                ?? 'The export process stopped unexpectedly (most likely a server timeout or memory limit).';
        }

        $state['has_report'] = is_file($this->reportPath());
        $state['now'] = microtime(true);

        return $state;
    }

    public function reportPath(): string
    {
        return $this->dir . '/report.html';
    }

    private function finishStep(string $key, string $state, ?string $detail, bool $write = true): void
    {
        $index = $this->index($key);
        if ($index === null) {
            return;
        }

        $this->state['steps'][$index]['state'] = $state;
        $this->state['steps'][$index]['detail'] = $detail;
        $this->state['steps'][$index]['finished_at'] = microtime(true);
        if (($this->state['current']['key'] ?? null) === $key) {
            $this->state['current'] = null;
        }

        if ($write) {
            $this->write();
        }
    }

    private function index(string $key): ?int
    {
        foreach ($this->state['steps'] ?? [] as $i => $step) {
            if ($step['key'] === $key) {
                return $i;
            }
        }
        return null;
    }

    private function write(): void
    {
        $this->state['updated_at'] = microtime(true);
        $this->lastWrite = $this->state['updated_at'];

        $path = $this->dir . '/progress.json';
        $tmp = $path . '.' . getmypid() . '.tmp';
        $json = json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false || @file_put_contents($tmp, $json) === false) {
            return;
        }

        if (!@rename($tmp, $path)) {
            @file_put_contents($path, $json, LOCK_EX);
            @unlink($tmp);
        }
    }

    private function release(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
        self::$active = null;
    }
}
