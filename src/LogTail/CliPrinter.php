<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use Cake\Console\ConsoleIo;

/**
 * Prints entries as cards through CakePHP's console output.
 *
 * Compact by default (short time, truncated fields, single-line message head);
 * `-v` switches to full date, untruncated fields and exception traces.
 * Level badges reuse the console's native per-level styles (`<error>`, …),
 * so no custom style registration is needed.
 */
class CliPrinter
{
    /**
     * @param \Cake\Console\ConsoleIo $io Console I/O.
     * @param string $basePath Application root stripped from file paths.
     */
    public function __construct(
        protected ConsoleIo $io,
        protected string $basePath,
    ) {
    }

    /**
     * Print one entry.
     *
     * @param \Crustum\DevConsole\LogTail\LogEntry $entry Entry.
     * @return void
     */
    public function print(LogEntry $entry): void
    {
        $verbose = $this->io->level() >= ConsoleIo::VERBOSE;
        $badge = $this->truncateClassOrType($entry->classOrType(), $verbose);
        $stamp = $verbose ? $entry->date() : $entry->time();
        $file = $this->shortFile($entry->file(), $verbose);

        $header = "<comment>┌</comment> <comment>{$stamp}</comment> <{$entry->level()}>{$badge}</{$entry->level()}>";
        if ($file !== null) {
            $header .= " <comment>{$file}</comment>";
        }

        $this->io->out($header);

        foreach ($this->messageLines($entry->message(), $verbose) as $line) {
            $this->io->out("<comment>│</comment> {$line}");
        }

        if ($verbose && $entry->exception() !== null) {
            foreach ($entry->exception()['trace'] as $index => $frame) {
                $traceFile = $this->stripBase($frame['file']) . ':' . ($frame['line'] ?? '?');
                $this->io->out('<comment>│</comment> <comment>' . ($index + 1) . ". {$traceFile}</comment>");
            }
        }

        $footer = $this->footer($entry);
        $this->io->out('<comment>└</comment>' . ($footer !== '' ? " {$footer}" : ''));
    }

    /**
     * Build the footer: origin plus context pairs joined with `•`.
     *
     * @param \Crustum\DevConsole\LogTail\LogEntry $entry Entry.
     * @return string Footer text (may be empty).
     */
    protected function footer(LogEntry $entry): string
    {
        $parts = [];
        $origin = $entry->origin();
        if (($origin['type'] ?? null) === 'http') {
            $parts[] = "<info>{$origin['method']} {$origin['path']}</info>";
            $parts[] = static::authLabel($origin);
        } elseif (isset($origin['command']) && is_string($origin['command']) && $origin['command'] !== '') {
            $parts[] = "<info>console: {$origin['command']}</info>";
        }

        foreach ($entry->context() as $key => $value) {
            $text = is_string($value) ? $value : var_export($value, true);
            $parts[] = "{$key}: {$text}";
        }

        return implode(' • ', $parts);
    }

    /**
     * Auth footer label (`Auth: 7 (a@b.c)`, `guest` when anonymous).
     *
     * @param array<string, mixed> $origin Entry origin.
     * @return string Label.
     */
    protected static function authLabel(array $origin): string
    {
        $auth = $origin['auth'] ?? null;
        if (!is_string($auth) && !is_int($auth)) {
            return 'guest';
        }

        $label = "Auth: {$auth}";
        $email = $origin['auth_email'] ?? null;
        if (is_string($email) && $email !== '') {
            $label .= " ({$email})";
        }

        return $label;
    }

    /**
     * Message split into display lines, truncated unless verbose.
     *
     * @param string $message Message.
     * @param bool $verbose Verbose output.
     * @return list<string> Lines.
     */
    protected function messageLines(string $message, bool $verbose): array
    {
        if ($message === '') {
            return ['<comment>No message.</comment>'];
        }

        $lines = explode("\n", $message);
        if (!$verbose) {
            $size = max(0, min($this->width() - 5, 145));
            foreach ($lines as $index => $line) {
                if (strlen($line) > $size) {
                    $lines[$index] = mb_substr($line, 0, $size) . '…';
                }
            }

            $lines = array_slice($lines, 0, 5);
        }

        return $lines;
    }

    /**
     * Shorten a badge (exception class) unless verbose.
     *
     * @param string $badge Badge text.
     * @param bool $verbose Verbose output.
     * @return string
     */
    protected function truncateClassOrType(string $badge, bool $verbose): string
    {
        if ($verbose) {
            return $badge;
        }

        $parts = explode('\\', $badge);
        if (count($parts) > 4) {
            return implode('\\', array_slice($parts, 0, 2)) . '\\…\\' . end($parts);
        }

        return $badge;
    }

    /**
     * Shorten an exception file path for the header.
     *
     * @param string|null $file Absolute file path.
     * @param bool $verbose Verbose output.
     * @return string|null Short path, or null when there is none.
     */
    protected function shortFile(?string $file, bool $verbose): ?string
    {
        if ($file === null) {
            return null;
        }

        $file = $this->stripBase($file);
        if ($verbose) {
            return $file;
        }

        $parts = explode('/', $file);
        if (count($parts) > 4) {
            $file = implode('/', array_slice($parts, 0, 2)) . '/…/' . end($parts);
        }

        $size = max(0, min($this->width() - 16, 145));
        if (strlen($file) > $size) {
            $file = mb_substr($file, 0, $size) . '…';
        }

        return $file === '…' ? null : $file;
    }

    /**
     * Strip the application base path from a file path.
     *
     * @param string $file Absolute file path.
     * @return string Relative path.
     */
    protected function stripBase(string $file): string
    {
        $prefix = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($file, $prefix)) {
            return substr($file, strlen($prefix));
        }

        return $file;
    }

    /**
     * Terminal width for truncation.
     *
     * @return int Columns, 100 when unknown.
     */
    protected function width(): int
    {
        $columns = getenv('COLUMNS');
        if (is_string($columns) && ctype_digit($columns) && (int)$columns > 20) {
            return (int)$columns;
        }

        return 100;
    }
}
