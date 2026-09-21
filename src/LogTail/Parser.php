<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use Throwable;

/**
 * Parses complete session-file lines into entries.
 *
 * The reader guarantees whole lines; anything that is not a well-formed entry
 * line is reported as malformed so the caller can warn and carry on.
 */
class Parser
{
    /**
     * Parse one line.
     *
     * @param string $line Complete line without trailing newline.
     * @return \Crustum\DevConsole\LogTail\LogEntry|null Entry, or null when malformed.
     */
    public function parse(string $line): ?LogEntry
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        try {
            return LogEntry::fromJson($line);
        } catch (Throwable) {
            return null;
        }
    }
}
