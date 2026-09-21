<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * PSR log level severity.
 *
 * Port of the severity map in `Crustum\Speculum\Watcher\LogWatcher`: numeric values
 * make "minimum level" comparisons trivial. Unknown levels score 0 (never enabled).
 */
class Severity
{
    /**
     * PSR level name (lowercase) to numeric severity.
     *
     * @var array<string, int>
     */
    protected const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    /**
     * Numeric severity for a level name, 0 when unknown.
     *
     * @param string $level Level name, any case.
     * @return int Severity value.
     */
    public static function value(string $level): int
    {
        return static::LEVELS[strtolower($level)] ?? 0;
    }

    /**
     * Whether a level meets a minimum level.
     *
     * @param string $level Message level.
     * @param string $minimum Minimum accepted level.
     * @return bool True when the message level is at or above the minimum.
     */
    public static function meets(string $level, string $minimum): bool
    {
        return static::value($level) >= static::value($minimum);
    }

    /**
     * Whether a level name is known.
     *
     * @param string $level Level name, any case.
     * @return bool True for the eight PSR levels.
     */
    public static function known(string $level): bool
    {
        return isset(static::LEVELS[strtolower($level)]);
    }
}
