<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * Stitch `LOGS/*.log` lines into entry blocks.
 *
 * CakePHP file logs are line-oriented except for exceptions, whose record is
 * one multi-line message (`Stack Trace:`, `- frame`, `Request URL:`, blanks…)
 * sharing the head line's timestamp/level. Without stitching, every trace
 * line becomes its own `info` noise event and the feed drowns on each 404.
 *
 * A line starting a new entry (`ServeEvent::isEntryStart`: TailEngine JSON or
 * `YYYY-MM-DD HH:MM:SS level:`) flushes the file's pending block; anything
 * else appends to it. Stray continuations with no head (backfill cut mid-block)
 * form their own block; stray blanks are dropped (they used to emit empty
 * `info` events). Blocks are capped by lines and bytes so a file that never
 * matches entry-start can never glue itself into one giant event.
 */
class FileBlockGrouper
{
    /**
     * @var array<string, list<string>> Basename to pending lines.
     */
    protected array $pending = [];

    /**
     * @var array<string, int> Basename to pending byte size.
     */
    protected array $sizes = [];

    /**
     * @param int $maxLines Lines per block before a forced flush.
     * @param int $maxBytes Bytes per block before a forced flush.
     */
    public function __construct(
        protected int $maxLines = 200,
        protected int $maxBytes = 65536,
    ) {
    }

    /**
     * Push one raw file line, returning blocks completed by it.
     *
     * @param string $file Basename of the log file (as `FileTail` reports it).
     * @param string $line Raw line without trailing newline.
     * @return list<array{file: string, text: string}> Completed blocks, oldest first.
     */
    public function push(string $file, string $line): array
    {
        if (ServeEvent::isEntryStart($line)) {
            $done = $this->flush($file);
            $this->pending[$file] = [$line];
            $this->sizes[$file] = strlen($line);

            return $done === null ? [] : [$done];
        }

        if (!isset($this->pending[$file])) {
            if (trim($line) === '') {
                return [];
            }

            $this->pending[$file] = [$line];
            $this->sizes[$file] = strlen($line);

            return [];
        }

        $this->pending[$file][] = $line;
        $this->sizes[$file] += strlen($line) + 1;

        if (count($this->pending[$file]) >= $this->maxLines || $this->sizes[$file] >= $this->maxBytes) {
            $done = $this->flush($file);

            return $done === null ? [] : [$done];
        }

        return [];
    }

    /**
     * Flush one file's pending block, if any.
     *
     * @param string $file Basename of the log file.
     * @return array{file: string, text: string}|null Block, or null when empty.
     */
    public function flush(string $file): ?array
    {
        if (empty($this->pending[$file] ?? [])) {
            return null;
        }

        $block = ['file' => $file, 'text' => implode("\n", $this->pending[$file])];
        unset($this->pending[$file], $this->sizes[$file]);

        return $block;
    }

    /**
     * Flush every file's pending block (tick end / shutdown).
     *
     * @return list<array{file: string, text: string}> Blocks in file-push order.
     */
    public function flushAll(): array
    {
        $blocks = [];

        foreach (array_keys($this->pending) as $file) {
            $block = $this->flush($file);

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}
