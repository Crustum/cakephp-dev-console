<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * Pure-PHP follow reader for one session file (`tail -F` semantics).
 *
 * No external `tail` binary (Windows-safe) and no `inotify`: polls size/mtime,
 * buffers partial lines across reads, and reopens the file when its inode changes
 * (log rotation) or it momentarily vanishes. Starts at the end of the file, so only
 * lines appended after following begins are delivered.
 */
class Reader
{
    /**
     * Poll interval in microseconds.
     *
     * @var int
     */
    protected const POLL_INTERVAL = 100000;

    /**
     * @param string $file Absolute path of the session file.
     * @param bool $fromStart Read existing content first (backfill), otherwise start at the end.
     */
    public function __construct(
        protected string $file,
        protected bool $fromStart = false,
    ) {
    }

    /**
     * Follow the file, invoking the callback per complete line.
     *
     * The callback receives the line without trailing newline and returns true to
     * keep following, false to stop early. Stops when `$maxLines` lines were delivered
     * (0 = unlimited), `$timeout` seconds elapsed (0 = return after draining what is
     * already there), or `$shouldStop` returns true (e.g. signal handler). Starts at
     * the end of the file unless constructed with `$fromStart`.
     *
     * @param callable(string): bool $onLine Line callback.
     * @param int $timeout Maximum seconds to run, 0 for drain-only.
     * @param int $maxLines Stop after this many lines, 0 for unlimited.
     * @param callable(): bool|null $shouldStop Early-stop callback, if any.
     * @return int Delivered line count.
     */
    public function follow(
        callable $onLine,
        int $timeout = 3600,
        int $maxLines = 0,
        ?callable $shouldStop = null,
    ): int {
        $handle = $this->open();
        $inode = $this->inode();
        $buffer = '';
        $delivered = 0;
        $deadline = $timeout > 0 ? microtime(true) + $timeout : 0.0;

        while (true) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            if (!$this->sameFile($inode)) {
                $this->close($handle);
                $handle = $this->open();
                $inode = $this->inode();
                $buffer = '';
            }

            if (is_resource($handle)) {
                $chunk = $this->read($handle);
                if ($chunk !== null && $chunk !== '') {
                    $buffer .= $chunk;
                    $delivered += $this->drain($buffer, $onLine, $maxLines > 0 ? $maxLines - $delivered : 0);

                    if ($maxLines > 0 && $delivered >= $maxLines) {
                        break;
                    }

                    continue;
                }
            }

            if ($deadline > 0.0 && microtime(true) >= $deadline) {
                break;
            }

            if ($deadline === 0.0) {
                break;
            }

            $this->dispatchSignals();
            usleep(static::POLL_INTERVAL);
        }

        $this->close($handle);

        return $delivered;
    }

    /**
     * Read one chunk, suppressing transient failures.
     *
     * A writer holding an exclusive lock (Windows enforces locks, so `fread`
     * fails with permission denied while `LOCK_EX` is held) or a file vanishing
     * mid-read surfaces as false — treated as "no data this tick", retried on
     * the next poll. Never lets a read warning escape: the app's error trap
     * would otherwise turn it into visible noise.
     *
     * @param mixed $handle Open file handle.
     * @return string|null Bytes read, or null when nothing could be read.
     */
    protected function read(mixed $handle): ?string
    {
        set_error_handler(static fn(): bool => true);
        try {
            $chunk = fread($handle, 8192);
        } finally {
            restore_error_handler();
        }

        return is_string($chunk) ? $chunk : null;
    }

    /**
     * Split complete lines off the buffer into the callback.
     *
     * @param string $buffer Buffered bytes (updated in place, partial tail kept).
     * @param callable(string): bool $onLine Line callback.
     * @param int $remaining Lines still wanted, 0 for unlimited.
     * @return int Delivered line count.
     */
    protected function drain(string &$buffer, callable $onLine, int $remaining): int
    {
        $delivered = 0;
        while (($pos = strpos($buffer, "\n")) !== false) {
            if ($remaining > 0 && $delivered >= $remaining) {
                break;
            }

            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $delivered++;

            if (!$onLine($line)) {
                $buffer = '';

                break;
            }
        }

        return $delivered;
    }

    /**
     * Whether the initial open already happened (reopens after rotation resume at the end).
     *
     * @var bool
     */
    protected bool $primed = false;

    /**
     * Open the file, positioned at the end (or start for backfill).
     *
     * @return mixed File handle, or null when the file is (momentarily) missing.
     */
    protected function open(): mixed
    {
        if (!is_file($this->file) || !is_readable($this->file)) {
            return null;
        }

        set_error_handler(static fn(): bool => true);
        try {
            $handle = fopen($this->file, 'rb');
        } finally {
            restore_error_handler();
        }

        if (!is_resource($handle)) {
            return null;
        }

        if ($this->primed || !$this->fromStart) {
            fseek($handle, 0, SEEK_END);
        }

        $this->primed = true;

        return $handle;
    }

    /**
     * Close a handle, if open.
     *
     * @param mixed $handle File handle or null.
     * @return void
     */
    protected function close(mixed $handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    /**
     * Current inode of the session file, null when missing.
     *
     * @return int|null
     */
    protected function inode(): ?int
    {
        clearstatcache(true, $this->file);

        set_error_handler(static fn(): bool => true);
        try {
            $stat = stat($this->file);
        } finally {
            restore_error_handler();
        }

        return $stat !== false ? (int)$stat['ino'] : null;
    }

    /**
     * Whether the file on disk is still the one being followed.
     *
     * @param int|null $inode Inode seen at open time.
     * @return bool True when unchanged (including still-missing).
     */
    protected function sameFile(?int $inode): bool
    {
        return $this->inode() === $inode;
    }

    /**
     * Dispatch pending signals when pcntl is available.
     *
     * @return void
     */
    protected function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
