<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use Cake\Core\Configure;

/**
 * Session files bridging emitting processes and tail sessions.
 *
 * The `logs tail` command creates one session file, and every
 * application process running the tail engine appends each captured
 * record to every live session file. Multiple concurrent sessions
 * each receive all logs.
 */
class SessionFiles
{
    /**
     * Session file extension.
     *
     * @var string
     */
    protected const EXTENSION = '.tail';

    /**
     * Seconds without writes before a session counts as dead (crashed/killed
     * consumers never reach `destroy()`). Pruned on `create()` so corpses stop
     * absorbing broadcast volume and `isActive()` goes quiet again.
     *
     * @var int
     */
    public const STALE_TTL = 900;

    /**
     * Per-file size cap. Broadcast trims past it (see `trim()`), so a dead or
     * flooded session can never grow into the hundreds of megabytes again.
     *
     * @var int
     */
    public const MAX_BYTES = 8388608;

    /**
     * @param string $path Session directory.
     */
    public function __construct(
        protected string $path,
    ) {
    }

    /**
     * Session files instance for the configured directory.
     *
     * Overridable via `Configure::write('DevConsole.tailPath', $dir)` (used by tests).
     *
     * @return static
     */
    public static function configured(): static
    {
        /** @var mixed $configured */
        $configured = Configure::read('DevConsole.tailPath');

        if (is_string($configured) && $configured !== '') {
            return new static($configured);
        }

        $tmp = defined('TMP') ? constant('TMP') : sys_get_temp_dir() . DIRECTORY_SEPARATOR;

        return new static(rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dev_console_tail');
    }

    /**
     * Whether any tail session is active.
     *
     * Fast-path guard for the engine: no sessions, no work.
     *
     * @return bool True when at least one session file exists.
     */
    public function isActive(): bool
    {
        return $this->all() !== [];
    }

    /**
     * All live session files (stale corpses pruned on sight).
     *
     * @return list<string> Absolute paths.
     */
    public function all(): array
    {
        $files = glob($this->path . DIRECTORY_SEPARATOR . '*' . static::EXTENSION) ?: [];
        $live = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if ($this->isStale($file)) {
                $this->destroy($file);

                continue;
            }

            $live[] = $file;
        }

        return $live;
    }

    /**
     * Delete sessions dead past the TTL. Returns the pruned count.
     *
     * @return int Pruned files.
     */
    public function prune(): int
    {
        $pruned = 0;

        foreach (glob($this->path . DIRECTORY_SEPARATOR . '*' . static::EXTENSION) ?: [] as $file) {
            if (is_file($file) && $this->isStale($file)) {
                $this->destroy($file);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Create a new session file (pruning corpses first).
     *
     * @return string Absolute path of the session file.
     */
    public function create(): string
    {
        $this->ensureDirectory();
        $this->prune();

        $file = $this->path . DIRECTORY_SEPARATOR . uniqid('logs-', true) . static::EXTENSION;
        touch($file);

        return $file;
    }

    /**
     * Append a line to every live session file, trimming past the size cap.
     *
     * Failures are swallowed: logging must never break the emitting application
     * (a session may vanish mid-write when its command exits).
     *
     * @param string $line Line without trailing newline.
     * @return void
     */
    public function broadcast(string $line): void
    {
        foreach ($this->all() as $file) {
            set_error_handler(static fn(): bool => true);
            try {
                $this->enforceCap($file);
                file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
                clearstatcache(true, $file);
            } finally {
                restore_error_handler();
            }
        }
    }

    /**
     * Whether a session file is a dead corpse (no writes past the TTL).
     *
     * Every broadcast appends, so mtime tracks liveness without heartbeats.
     * A quiet-but-live viewer past the TTL is pruned and transparently
     * recreated on the next broadcast (readers treat the shrink as rotation).
     *
     * @param string $file Absolute path.
     * @return bool True when stale.
     */
    protected function isStale(string $file): bool
    {
        clearstatcache(true, $file);

        set_error_handler(static fn(): bool => true);
        try {
            $mtime = filemtime($file);
        } finally {
            restore_error_handler();
        }

        return $mtime !== false && time() - $mtime > static::STALE_TTL;
    }

    /**
     * Trim an oversized session file back under the cap, keeping the tail.
     *
     * Readers see a shrink and reopen from the start (rotation path), so a
     * trim may replay the kept tail as duplicates — viewer-tolerable, and far
     * cheaper than unbounded growth.
     *
     * @param string $file Absolute path.
     * @return void
     */
    protected function enforceCap(string $file): void
    {
        clearstatcache(true, $file);

        set_error_handler(static fn(): bool => true);
        try {
            $size = filesize($file);
        } finally {
            restore_error_handler();
        }

        if ($size === false || $size <= static::MAX_BYTES) {
            return;
        }

        $keep = max(1, (int)(static::MAX_BYTES / 4));

        set_error_handler(static fn(): bool => true);
        try {
            $handle = fopen($file, 'rb');

            if (!is_resource($handle)) {
                return;
            }

            try {
                fseek($handle, -$keep, SEEK_END);
                $tail = fread($handle, $keep);
            } finally {
                fclose($handle);
            }

            if (!is_string($tail) || $tail === '') {
                return;
            }

            $pos = strpos($tail, "\n");
            $tail = $pos === false ? $tail : substr($tail, $pos + 1);

            file_put_contents($file, $tail, LOCK_EX);
            clearstatcache(true, $file);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Destroy a session file.
     *
     * @param string $file Absolute path.
     * @return void
     */
    public function destroy(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Ensure the session directory exists.
     *
     * @return void
     */
    protected function ensureDirectory(): void
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }
    }
}
