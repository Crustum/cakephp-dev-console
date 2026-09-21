<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use DateTimeImmutable;
use Throwable;

/**
 * Maps captured records to TUI wire-protocol events (`PROTOCOL.md`, v1).
 *
 * The collector is stateless by design: this class only translates, it never
 * filters, counts or buffers. The OpenTUI host owns all of that state.
 */
class ServeEvent
{
    /**
     * Wire protocol version.
     *
     * @var int
     */
    public const VERSION = 1;

    /**
     * Known live/file source names.
     *
     * @var list<string>
     */
    public const SOURCES = ['cake_live', 'cake_file'];

    /**
     * Start of a CakePHP file-log entry (`YYYY-MM-DD HH:MM:SS level: message`).
     *
     * @var string
     */
    public const ENTRY_START_PATTERN = '/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\s+(\w+):\s?(.*)$/';

    /**
     * Build an `event` message from a live engine record.
     *
     * @param \Crustum\DevConsole\LogTail\LogEntry $entry Record.
     * @param string $source Source name (`cake_live`).
     * @return array{v: int, t: string, source: string, ts: float, level: string, msg: string, raw: string, meta: array<string, mixed>}
     */
    public static function fromLogEntry(LogEntry $entry, string $source = 'cake_live'): array
    {
        return [
            'v' => static::VERSION,
            't' => 'event',
            'source' => $source,
            'ts' => $entry->timestamp(),
            'level' => strtolower($entry->level()),
            'msg' => static::message($entry),
            'raw' => $entry->toJson(),
            'meta' => [
                'scopes' => $entry->scopes(),
                'context' => $entry->context(),
                'exception' => $entry->exception(),
                'origin' => $entry->origin(),
            ],
        ];
    }

    /**
     * Best-effort parse of one `LOGS/*.log` line into an `event` message.
     *
     * Tries the TailEngine JSON shape first, then the default CakePHP file
     * format (`YYYY-MM-DD HH:MM:SS level: message`); anything else is emitted
     * as an `info` event with the original line as both `msg` and `raw` so no
     * file content is ever silently dropped. Stitched multi-line blocks parse
     * level and timestamp on their head line while `msg` and `raw` keep the
     * whole block (cards render it, filters match it).
     *
     * @param string $line Raw file line without trailing newline.
     * @param string $file Basename of the log file the line came from.
     * @return array{v: int, t: string, source: string, ts: float, level: string, msg: string, raw: string, meta: array<string, mixed>}
     */
    public static function fromFileLine(string $line, string $file): array
    {
        $raw = $line;
        $pos = strpos($line, "\n");
        $head = $pos === false ? $line : substr($line, 0, $pos);
        $rest = $pos === false ? '' : substr($line, $pos);

        try {
            $entry = LogEntry::fromJson($head);
            $event = static::fromLogEntry($entry, 'cake_file');
            $event['meta']['file'] = $file;
            $event['raw'] = $raw;

            return $event;
        } catch (Throwable) {
        }

        $matches = [];
        if (preg_match(static::ENTRY_START_PATTERN, $head, $matches) === 1) {
            $level = strtolower($matches[2]);

            return [
                'v' => static::VERSION,
                't' => 'event',
                'source' => 'cake_file',
                'ts' => static::timestamp($matches[1]),
                'level' => Severity::known($level) ? $level : 'info',
                'msg' => $matches[3] . $rest,
                'raw' => $raw,
                'meta' => ['file' => $file],
            ];
        }

        return [
            'v' => static::VERSION,
            't' => 'event',
            'source' => 'cake_file',
            'ts' => microtime(true),
            'level' => 'info',
            'msg' => $line,
            'raw' => $raw,
            'meta' => ['file' => $file],
        ];
    }

    /**
     * Whether a raw `LOGS/*.log` line starts a new entry (vs continuing the
     * previous one: `Stack Trace:`, `- frame`, `Request URL:`, blanks…).
     *
     * TailEngine JSON lines and `YYYY-MM-DD HH:MM:SS level:` lines start an
     * entry; anything else is a continuation for the grouper to stitch.
     *
     * @param string $line Raw file line without trailing newline.
     * @return bool True when the line starts a new entry.
     */
    public static function isEntryStart(string $line): bool
    {
        if (trim($line) === '') {
            return false;
        }

        try {
            LogEntry::fromJson($line);

            return true;
        } catch (Throwable) {
            // Fall through to the text format below.
        }

        return preg_match(static::ENTRY_START_PATTERN, $line) === 1;
    }

    /**
     * Human message for a live record, with exception context appended.
     *
     * @param \Crustum\DevConsole\LogTail\LogEntry $entry Record.
     * @return string
     */
    protected static function message(LogEntry $entry): string
    {
        $message = $entry->message();
        $exception = $entry->exception();

        if ($exception === null) {
            return $message;
        }

        return $message . ' {' . $exception['class'] . ': ' . $exception['message'] . '}';
    }

    /**
     * Parse a datetime string to float seconds, falling back to now.
     *
     * @param string $datetime Datetime string.
     * @return float Seconds with ms precision.
     */
    protected static function timestamp(string $datetime): float
    {
        try {
            $parsed = new DateTimeImmutable($datetime);

            return (float)$parsed->format('U.u');
        } catch (Throwable) {
            return microtime(true);
        }
    }
}
