<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use Cake\Log\Engine\BaseLog;
use Cake\Log\Log;
use Stringable;
use Throwable;

/**
 * Catch-all log engine forwarding records to live tail sessions.
 *
 * Mirrors `Crustum\Speculum\Watcher\SpeculumLogEngine`: registered with empty `levels`
 * and `scopes` so CakePHP delivers every message, then write-through (no buffering)
 * as one JSON line per session file. Unlike Speculum it never touches a database and
 * never filters: the tail command (or the future TUI host) owns all filtering.
 */
class TailEngine extends BaseLog
{
    /**
     * Config key used for the engine registration.
     *
     * @var string
     */
    public const CONFIG_KEY = 'dev_console_tail';

    /**
     * Register the catch-all engine, replacing any previous registration.
     *
     * @return void
     */
    public static function register(): void
    {
        if (Log::getConfig(static::CONFIG_KEY)) {
            Log::drop(static::CONFIG_KEY);
        }

        Log::setConfig(static::CONFIG_KEY, [
            'className' => static::class,
            'levels' => [],
            'scopes' => [],
        ]);
    }

    /**
     * Drop the engine registration, if present.
     *
     * @return void
     */
    public static function unregister(): void
    {
        if (Log::getConfig(static::CONFIG_KEY)) {
            Log::drop(static::CONFIG_KEY);
        }
    }

    /**
     * @inheritDoc
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        try {
            $sessions = SessionFiles::configured();

            if (!$sessions->isActive()) {
                return;
            }

            $sessions->broadcast(LogEntry::fromRecord((string)$level, $message, $context)->toJson());
        } catch (Throwable) {
            // Logging must never break the emitting application.
        }
    }
}
