<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * Suppress live replays of the start-up file backfill.
 *
 * `logs serve` reads history from `LOGS/*.log` once, then streams only the
 * live session: records logged between session creation and the backfill read
 * exist in both places. Remembered backfill signatures suppress at most one
 * live replay each, inside a short window after start; afterwards the set is
 * dropped and every live record passes.
 */
class BackfillDedup
{
    /**
     * @var array<string, int> Signature to remaining suppressions.
     */
    protected array $signatures = [];

    /**
     * @var float Start time in seconds with microseconds.
     */
    protected float $startedAt;

    /**
     * @param int $windowSeconds Seconds after start while suppression applies.
     * @param int $maxSignatures Cap on remembered signatures.
     */
    public function __construct(
        protected int $windowSeconds = 3,
        protected int $maxSignatures = 500,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * Remember one backfilled event.
     *
     * @param string $level Lowercase level name.
     * @param string $msg Full message text (head line is significant).
     * @param float $ts Event timestamp in seconds.
     * @return void
     */
    public function remember(string $level, string $msg, float $ts): void
    {
        $key = $this->key($level, $msg, $ts);

        if (!isset($this->signatures[$key]) && count($this->signatures) >= $this->maxSignatures) {
            return;
        }

        $this->signatures[$key] = ($this->signatures[$key] ?? 0) + 1;
    }

    /**
     * Whether a live event replays a remembered backfill event.
     *
     * @param string $level Lowercase level name.
     * @param string $msg Full message text (head line is significant).
     * @param float $ts Event timestamp in seconds.
     * @return bool True when the event must be skipped.
     */
    public function isDuplicate(string $level, string $msg, float $ts): bool
    {
        if (microtime(true) - $this->startedAt > $this->windowSeconds) {
            $this->signatures = [];

            return false;
        }

        $key = $this->key($level, $msg, $ts);

        if (($this->signatures[$key] ?? 0) <= 0) {
            return false;
        }

        $this->signatures[$key]--;

        return true;
    }

    /**
     * Signature of one event: level, message head line and whole second.
     *
     * @param string $level Lowercase level name.
     * @param string $msg Full message text.
     * @param float $ts Event timestamp in seconds.
     * @return string Signature.
     */
    protected function key(string $level, string $msg, float $ts): string
    {
        $pos = strpos($msg, "\n");
        $head = $pos === false ? $msg : substr($msg, 0, $pos);

        return strtolower($level) . "\0" . $head . "\0" . (int)$ts;
    }
}
