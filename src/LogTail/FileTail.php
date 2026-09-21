<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * Multi-file byte tailer for the `cake_file` source (`LOGS/*.log` backfill + live).
 *
 * Tracks per-file inode + offset so appended bytes are yielded exactly once and
 * rotation (inode change or shrink) reopens from the start. Partial trailing
 * lines are buffered until their newline lands.
 */
class FileTail
{
    /**
     * @var array<string, int|null> File path to last seen inode.
     */
    protected array $inodes = [];

    /**
     * @var array<string, int> File path to read offset.
     */
    protected array $offsets = [];

    /**
     * @var array<string, string> File path to buffered partial line.
     */
    protected array $buffers = [];

    /**
     * @param list<string> $files Absolute log file paths to follow.
     */
    public function __construct(
        protected array $files,
    ) {
        foreach ($files as $file) {
            $this->inodes[$file] = null;
            $this->offsets[$file] = 0;
            $this->buffers[$file] = '';
        }
    }

    /**
     * Read the last `$maxLines` lines per file, oldest first.
     *
     * Files are visited in sorted order for deterministic output.
     *
     * @param int $maxLines Lines per file, 0 for none.
     * @return list<array{file: string, line: string}> Lines with their basename.
     */
    public function backfill(int $maxLines): array
    {
        if ($maxLines <= 0) {
            foreach ($this->files as $file) {
                $this->seekEnd($file);
            }

            return [];
        }

        $out = [];
        $files = $this->files;
        sort($files);

        foreach ($files as $file) {
            foreach (static::lastLines($file, $maxLines) as $line) {
                $out[] = ['file' => basename($file), 'line' => $line];
            }

            $this->seekEnd($file);
        }

        return $out;
    }

    /**
     * Read bytes appended since the last call.
     *
     * @return array{lines: list<array{file: string, line: string}>, reopened: list<string>} New lines plus basenames that were reopened after rotation.
     */
    public function poll(): array
    {
        $lines = [];
        $reopened = [];

        foreach ($this->files as $file) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                continue;
            }

            if (!is_readable($file)) {
                continue;
            }

            $inode = $this->inode($file);
            $size = filesize($file);

            if ($size === false) {
                continue;
            }

            $previous = $this->inodes[$file] ?? null;

            if ($previous !== null && ($inode !== $previous || $size < $this->offsets[$file])) {
                $reopened[] = basename($file);
                $this->offsets[$file] = 0;
                $this->buffers[$file] = '';
            }

            $this->inodes[$file] = $inode;

            if ($size <= $this->offsets[$file]) {
                continue;
            }

            $chunk = $this->readAt($file, $this->offsets[$file], $size - $this->offsets[$file]);

            if ($chunk === null) {
                continue;
            }

            $this->offsets[$file] = $size;
            $this->buffers[$file] .= $chunk;

            while (($pos = strpos($this->buffers[$file], "\n")) !== false) {
                $line = substr($this->buffers[$file], 0, $pos);
                $this->buffers[$file] = substr($this->buffers[$file], $pos + 1);
                $lines[] = ['file' => basename($file), 'line' => $line];
            }
        }

        return ['lines' => $lines, 'reopened' => $reopened];
    }

    /**
     * Position tracking at the end of a file (backfill done / live from here).
     *
     * @param string $file Absolute path.
     * @return void
     */
    protected function seekEnd(string $file): void
    {
        clearstatcache(true, $file);

        $this->inodes[$file] = $this->inode($file);

        $size = is_file($file) ? filesize($file) : false;
        $this->offsets[$file] = $size === false ? 0 : $size;
        $this->buffers[$file] = '';
    }

    /**
     * Current inode of a file, null when it cannot be stated.
     *
     * @param string $file Absolute path.
     * @return int|null
     */
    protected function inode(string $file): ?int
    {
        set_error_handler(static fn(): bool => true);
        try {
            $stat = stat($file);
        } finally {
            restore_error_handler();
        }

        return $stat !== false ? (int)$stat['ino'] : null;
    }

    /**
     * Read `$length` bytes at `$offset`, suppressing transient failures.
     *
     * Windows enforces file locks, so a concurrent writer can make the read
     * fail — treated as "no data this tick", retried on the next poll.
     *
     * @param string $file Absolute path.
     * @param int $offset Byte offset.
     * @param int $length Bytes to read.
     * @return string|null Bytes, or null when nothing could be read.
     */
    protected function readAt(string $file, int $offset, int $length): ?string
    {
        set_error_handler(static fn(): bool => true);
        try {
            $handle = fopen($file, 'rb');

            if (!is_resource($handle)) {
                return null;
            }

            try {
                fseek($handle, $offset);

                $chunk = fread($handle, max(1, min($length, 65536)));
            } finally {
                fclose($handle);
            }
        } finally {
            restore_error_handler();
        }

        return is_string($chunk) ? $chunk : null;
    }

    /**
     * Read the last `$maxLines` lines of a file.
     *
     * Reads backwards in 8 KiB blocks so large logs never load fully.
     *
     * @param string $file Absolute path.
     * @param int $maxLines Lines wanted.
     * @return list<string> Oldest first, without trailing newlines.
     */
    protected static function lastLines(string $file, int $maxLines): array
    {
        set_error_handler(static fn(): bool => true);
        try {
            $handle = is_readable($file) ? fopen($file, 'rb') : false;
        } finally {
            restore_error_handler();
        }

        if (!is_resource($handle)) {
            return [];
        }

        try {
            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);

            if ($size === false || $size <= 0) {
                return [];
            }

            $data = '';
            $pos = $size;

            while ($pos > 0 && substr_count($data, "\n") <= $maxLines) {
                $step = min(8192, $pos);
                $pos -= $step;
                fseek($handle, $pos);

                $chunk = fread($handle, $step);

                if (!is_string($chunk) || $chunk === '') {
                    break;
                }

                $data = $chunk . $data;
            }

            $lines = explode("\n", $data);

            if (end($lines) === '') {
                array_pop($lines);
            }

            return array_slice($lines, -$maxLines);
        } finally {
            fclose($handle);
        }
    }
}
