<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Process;

use Cake\Core\Configure;
use Crustum\DevConsole\Service\NodePackageManager;
use ReflectionClass;
use Throwable;

/**
 * Static registry for dev processes run by the `dev` command.
 *
 * Port of `Illuminate\Foundation\DevCommands`, adapted to CakePHP:
 * `base_path()` is `ROOT`, the console guard is `PHP_SAPI`,
 * and the default processes are CakePHP commands.
 *
 * @phpstan-type ProcessArray array{name: string, command: string, source: array{file?: string, line?: int, class?: string, function?: string}, color: string, priority: int}
 */
class Processes
{
    /**
     * Resolved NodePackageManager instance.
     *
     * @var \Crustum\DevConsole\Service\NodePackageManager|null
     */
    protected static ?NodePackageManager $packageManager = null;

    /**
     * Counter for color reuse once the palette is exhausted.
     *
     * @var int
     */
    protected static int $colorCount = 0;

    /**
     * Registered dev processes, keyed by name.
     *
     * @var array<string, \Crustum\DevConsole\Process\Process>
     */
    protected static array $commands = [];

    /**
     * Names of processes to include when running `dev`.
     *
     * @var array<int, string>
     */
    protected static array $only = [];

    /**
     * Names of processes to exclude when running `dev`.
     *
     * @var array<int, string>
     */
    protected static array $except = [];

    /**
     * Explicit ordering for named processes.
     *
     * @var array<int, string>
     */
    protected static array $order = [];

    /**
     * Display mode for the `dev` command.
     *
     * @var \Crustum\DevConsole\Process\ProcessMode
     */
    protected static ProcessMode $mode = ProcessMode::TABS;

    /**
     * Whether the mode was set explicitly via the static API.
     *
     * An explicit call wins over the `DevConsole.mode` config value.
     *
     * @var bool
     */
    protected static bool $modeCustomized = false;

    /**
     * Whether to include timestamps in `dev` output.
     *
     * @var bool
     */
    protected static bool $withTimestamps = false;

    /**
     * Whether to automatically restart a failed `dev` process.
     *
     * @var bool
     */
    protected static bool $autoRestart = true;

    /**
     * Whether to exclude vendor-registered processes.
     *
     * @var bool
     */
    protected static bool $withoutVendorCommands = false;

    /**
     * Whether to exclude framework default processes.
     *
     * @var bool
     */
    protected static bool $withoutDefaultCommands = false;

    /**
     * Lines of output buffered per process in tabs mode.
     *
     * @var int|null
     */
    protected static ?int $bufferSize = null;

    /**
     * Lines of output buffered in total in stream mode.
     *
     * @var int|null
     */
    protected static ?int $streamBufferSize = null;

    /**
     * Application root path override (tests).
     *
     * @var string|null
     */
    protected static ?string $rootPath = null;

    /**
     * Override the application root path.
     *
     * @param string|null $path Root path or null to use the default.
     * @return void
     */
    public static function setRootPath(?string $path): void
    {
        self::$rootPath = $path;
        self::$packageManager = null;
    }

    /**
     * Get the pid file tracking the supervised `dev` run.
     *
     * Named per application so several apps on one machine do not collide.
     *
     * @return string Absolute pid file path.
     */
    public static function pidFile(): string
    {
        $app = preg_replace('/[^A-Za-z0-9_-]/', '-', basename(self::rootPath()));

        if (!is_string($app) || $app === '') {
            $app = 'app';
        }

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "cake-dev-{$app}.pid";
    }

    /**
     * Get the pid of the currently supervised run, if still alive.
     *
     * A stale pid file (process gone) is removed and reported as no run.
     * Like `Monitor` pid files, liveness alone is trusted: the guard message
     * shows the process command line so a recycled pid is easy to spot.
     *
     * @return int|null Live supervised pid, or null when nothing runs.
     */
    public static function runningPid(): ?int
    {
        $file = self::pidFile();

        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $pid = $raw === false ? 0 : (int)trim($raw);

        if ($pid > 0 && self::isPidRunning($pid)) {
            return $pid;
        }

        unlink($file);

        return null;
    }

    /**
     * Read a process command line, or null when unreadable.
     *
     * @param int $pid Process id.
     * @return string|null
     */
    public static function pidCommandLine(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('exec')) {
                return null;
            }

            exec(
                'wmic process where "ProcessId=' . $pid . '" get CommandLine,ProcessId /FORMAT:CSV 2> NUL',
                $lines,
            );

            foreach ($lines as $line) {
                // CSV columns: Node, CommandLine, ProcessId.
                $parts = str_getcsv(trim($line), ',', '"', '');

                if (count($parts) !== 3) {
                    continue;
                }

                if (is_numeric($parts[2]) && (int)$parts[2] === $pid) {
                    return (string)$parts[1];
                }
            }

            return null;
        }

        $cmdlineFile = '/proc/' . $pid . '/cmdline';

        if (!is_file($cmdlineFile)) {
            return null;
        }

        $raw = file_get_contents($cmdlineFile);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return str_replace("\0", ' ', trim($raw));
    }

    /**
     * Check whether a process id is currently running.
     *
     * Best effort without extensions: `tasklist` on Windows, `posix_kill`
     * with signal 0 or `/proc` on Unix. When nothing can verify, the pid is
     * treated as stale so a missing extension never blocks `dev` forever.
     * A zombie (dead but unreaped) still answers signal 0, so it is
     * reported as not running.
     *
     * @param int $pid Process id.
     * @return bool
     */
    public static function isPidRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('exec')) {
                return false;
            }

            exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2> NUL', $lines);

            foreach ($lines as $line) {
                if (str_contains($line, '"' . $pid . '"')) {
                    return true;
                }
            }

            return false;
        }

        if (function_exists('posix_kill')) {
            try {
                $signaled = posix_kill($pid, 0);
            } catch (Throwable) {
                return false;
            }

            if (!$signaled) {
                return false;
            }

            return !self::isZombie($pid);
        }

        return file_exists('/proc/' . $pid);
    }

    /**
     * Check whether a pid is a zombie (dead but not yet reaped).
     *
     * Only detectable via `/proc`, so non-Linux Unix always returns false.
     *
     * @param int $pid Process id.
     * @return bool
     */
    protected static function isZombie(int $pid): bool
    {
        $statFile = '/proc/' . $pid . '/stat';

        if (!is_file($statFile)) {
            return false;
        }

        $stat = file_get_contents($statFile);

        if (!is_string($stat)) {
            return false;
        }

        $close = strrpos($stat, ')');

        if ($close === false) {
            return false;
        }

        $state = ltrim(substr($stat, $close + 1));

        return $state !== '' && $state[0] === 'Z';
    }

    /**
     * Terminate a process tree.
     *
     * Graceful mode asks the tree to stop (`taskkill /PID /T` on Windows so
     * cleanup handlers run, `SIGTERM` on Unix); force mode kills
     * unconditionally (`/T /F`, `SIGKILL`). Failures just mean the tree is
     * already gone.
     *
     * @param int $pid Root process id.
     * @param bool $force Force-kill instead of asking gracefully.
     * @return void
     */
    public static function killTree(int $pid, bool $force = true): void
    {
        if ($pid <= 0) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('exec')) {
                return;
            }

            $forceFlag = $force ? ' /F' : '';

            exec('taskkill /PID ' . $pid . ' /T' . $forceFlag . ' 2> NUL', $output, $result);

            return;
        }

        if (!function_exists('posix_kill')) {
            return;
        }

        try {
            // 15 = SIGTERM, 9 = SIGKILL (literals: the constants do not
            // exist on Windows builds).
            posix_kill($pid, $force ? 9 : 15);
        } catch (Throwable) {
            // Already gone.
        }
    }

    /**
     * Get the application root path.
     *
     * @return string
     */
    public static function rootPath(): string
    {
        if (self::$rootPath !== null) {
            return self::$rootPath;
        }

        if (defined('ROOT')) {
            /** @var string $root */
            $root = constant('ROOT');

            return $root;
        }

        return (string)getcwd();
    }

    /**
     * Reset all registry state (tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$packageManager = null;
        self::$colorCount = 0;
        self::$commands = [];
        self::$only = [];
        self::$except = [];
        self::$order = [];
        self::$mode = ProcessMode::TABS;
        self::$modeCustomized = false;
        self::$withTimestamps = false;
        self::$autoRestart = true;
        self::$withoutVendorCommands = false;
        self::$withoutDefaultCommands = false;
        self::$bufferSize = null;
        self::$streamBufferSize = null;
    }

    /**
     * Register the default development processes.
     *
     * Registers the CakePHP dev server and, when a `package.json` exists,
     * the frontend watcher via the detected Node package manager.
     *
     * @return void
     */
    public static function registerDefaults(): void
    {
        if (!static::runningInConsole()) {
            return;
        }

        self::cake('server', 'server');

        if (is_file(self::rootPath() . DIRECTORY_SEPARATOR . 'package.json')) {
            self::node('dev', 'vite');
        }
    }

    /**
     * Register a development process.
     *
     * The caller location is captured via `debug_backtrace()` so `dev_list`
     * can show where each process was registered. When two processes share
     * a name, the higher priority wins (userland > vendor > default).
     *
     * @param string $command Shell command.
     * @param string|null $name Tab label.
     * @param int|null $priority Explicit priority; null resolves it from the caller.
     * @return \Crustum\DevConsole\Process\Process
     */
    public static function register(string $command, ?string $name = null, ?int $priority = null): Process
    {
        if (!static::runningInConsole()) {
            return new Process('', [], '');
        }

        /** @var array<int, array{file?: string, line?: int, class?: string, function?: string}> $trace */
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $source = self::resolveSource($trace);
        $priority ??= self::resolvePriority($trace);

        $devCommand = new Process($command, $source, $name, $priority);

        $existing = self::$commands[$devCommand->name()] ?? null;

        if ($existing === null || $devCommand->priority() >= $existing->priority()) {
            self::$commands[$devCommand->name()] = $devCommand;
        }

        return $devCommand;
    }

    /**
     * Register multiple development processes from config definitions.
     *
     * Each definition is `['command' => ..., 'name' => ?, 'color' => ?]`.
     * Entries without a non-empty `command` string are skipped. Config
     * processes register with userland priority, so they override
     * same-named defaults.
     *
     * @param array<int, mixed> $definitions Process definitions.
     * @return void
     */
    public static function registerMany(array $definitions): void
    {
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $command = $definition['command'] ?? null;
            $name = $definition['name'] ?? null;
            $color = $definition['color'] ?? null;
            if (!is_string($command)) {
                continue;
            }

            if ($command === '') {
                continue;
            }

            $process = self::register(
                $command,
                is_string($name) && $name !== '' ? $name : null,
                Process::PRIORITY_USERLAND,
            );

            if (is_string($color) && $color !== '') {
                $process->color($color);
            }
        }
    }

    /**
     * Register a CakePHP console command as a dev process.
     *
     * Prefixes the command with `php bin/cake.php`.
     *
     * @param string $command Cake command, e.g. `server`.
     * @param string|null $name Tab label.
     * @return \Crustum\DevConsole\Process\Process
     */
    public static function cake(string $command, ?string $name = null): Process
    {
        return self::register("php bin/cake.php {$command}", $name ?? Process::nameFromCommand($command));
    }

    /**
     * Register a Node script as a dev process.
     *
     * Prefixes the script with the detected package manager's run command.
     *
     * @param string $command Script name, e.g. `dev`.
     * @param string|null $name Tab label.
     * @return \Crustum\DevConsole\Process\Process
     */
    public static function node(string $command, ?string $name = null): Process
    {
        return self::register(
            self::getPackageManager()->getRunCommand($command),
            $name ?? Process::nameFromCommand($command),
        );
    }

    /**
     * Register a Node binary as a dev process.
     *
     * Prefixes the binary with the detected package manager's exec command.
     *
     * @param string $command Binary invocation, e.g. `vite build --watch`.
     * @param string|null $name Tab label.
     * @return \Crustum\DevConsole\Process\Process
     */
    public static function nodeExec(string $command, ?string $name = null): Process
    {
        return self::register(
            self::getPackageManager()->getExecCommand($command),
            $name ?? Process::nameFromCommand($command),
        );
    }

    /**
     * Get the registered dev processes, filtered and colorized.
     *
     * @return array<int, ProcessArray>
     */
    public static function commands(): array
    {
        $commands = [];

        foreach (self::$commands as $command) {
            if (self::$withoutVendorCommands && $command->priority() === Process::PRIORITY_VENDOR) {
                continue;
            }

            if (self::$withoutDefaultCommands && $command->priority() === Process::PRIORITY_DEFAULT) {
                continue;
            }

            $cmd = $command->toArray();
            if (self::$only !== [] && !in_array($cmd['name'], self::$only, true)) {
                continue;
            }

            if (in_array($cmd['name'], self::$except, true)) {
                continue;
            }

            /** @var ProcessArray $cmd */
            $commands[] = $cmd;
        }

        $commands = self::applyOrder($commands);

        return self::fillInEmptyColors($commands);
    }

    /**
     * Use inline mode: merged output, no TUI.
     *
     * @return void
     */
    public static function inline(): void
    {
        self::$mode = ProcessMode::INLINE;
        self::$modeCustomized = true;
    }

    /**
     * Use stream mode: merged output in an interactive TUI.
     *
     * @return void
     */
    public static function stream(): void
    {
        self::$mode = ProcessMode::STREAM;
        self::$modeCustomized = true;
    }

    /**
     * Use tabs mode: each process in its own tab (default).
     *
     * @return void
     */
    public static function tabs(): void
    {
        self::$mode = ProcessMode::TABS;
        self::$modeCustomized = true;
    }

    /**
     * Get the configured display mode.
     *
     * Falls back to the `DevConsole.mode` config value (`tabs`, `stream`
     * or `inline`, case-insensitive) unless the mode was set explicitly via
     * the static API. Unknown config values are ignored.
     *
     * @return \Crustum\DevConsole\Process\ProcessMode
     */
    public static function mode(): ProcessMode
    {
        if (!self::$modeCustomized) {
            $configured = Configure::read('DevConsole.mode');

            if (is_string($configured) && $configured !== '') {
                $mode = ProcessMode::tryFrom(strtolower($configured));

                if ($mode !== null) {
                    return $mode;
                }
            }
        }

        return self::$mode;
    }

    /**
     * Enable timestamps in `dev` output.
     *
     * @return void
     */
    public static function withTimestamps(): void
    {
        self::$withTimestamps = true;
    }

    /**
     * Whether timestamps should be included in `dev` output.
     *
     * @return bool
     */
    public static function shouldIncludeTimestamps(): bool
    {
        return self::$withTimestamps;
    }

    /**
     * Disable automatic restart of failed `dev` processes.
     *
     * @return void
     */
    public static function disableAutoRestart(): void
    {
        self::$autoRestart = false;
    }

    /**
     * Whether failed `dev` processes should automatically restart.
     *
     * @return bool
     */
    public static function shouldAutoRestart(): bool
    {
        return self::$autoRestart;
    }

    /**
     * Set lines of output buffered per process in tabs mode.
     *
     * @param int $lines Line count.
     * @return void
     */
    public static function bufferSize(int $lines): void
    {
        self::$bufferSize = $lines;
    }

    /**
     * Get lines of output buffered per process in tabs mode.
     *
     * @return int|null
     */
    public static function getBufferSize(): ?int
    {
        return self::$bufferSize;
    }

    /**
     * Set lines of output buffered in total in stream mode.
     *
     * @param int $lines Line count.
     * @return void
     */
    public static function streamBufferSize(int $lines): void
    {
        self::$streamBufferSize = $lines;
    }

    /**
     * Get lines of output buffered in total in stream mode.
     *
     * @return int|null
     */
    public static function getStreamBufferSize(): ?int
    {
        return self::$streamBufferSize;
    }

    /**
     * Only include these process names when running `dev`.
     *
     * @param string ...$names Process names.
     * @return void
     */
    public static function only(string ...$names): void
    {
        self::$only = array_values($names);
    }

    /**
     * Exclude these process names when running `dev`.
     *
     * @param string ...$names Process names.
     * @return void
     */
    public static function except(string ...$names): void
    {
        self::$except = array_values($names);
    }

    /**
     * Set the order in which named processes run.
     *
     * @param array<int, string> $names Ordered process names.
     * @return void
     */
    public static function order(array $names): void
    {
        self::$order = array_values($names);
    }

    /**
     * Exclude processes registered by vendor packages.
     *
     * @return void
     */
    public static function withoutVendorCommands(): void
    {
        self::$withoutVendorCommands = true;
    }

    /**
     * Exclude framework default processes.
     *
     * @return void
     */
    public static function withoutDefaultCommands(): void
    {
        self::$withoutDefaultCommands = true;
    }

    /**
     * Sort processes by the configured order.
     *
     * @param array<int, ProcessArray> $commands Processes.
     * @return array<int, ProcessArray>
     */
    protected static function applyOrder(array $commands): array
    {
        if (self::$order === []) {
            return $commands;
        }

        $positions = array_flip(self::$order);

        usort($commands, fn(array $a, array $b): int => ($positions[$a['name']] ?? PHP_INT_MAX) <=> ($positions[$b['name']] ?? PHP_INT_MAX));

        return $commands;
    }

    /**
     * Assign palette colors to processes without an explicit color.
     *
     * @param array<int, ProcessArray> $commands Processes.
     * @return array<int, ProcessArray>
     */
    protected static function fillInEmptyColors(array $commands): array
    {
        foreach ($commands as &$command) {
            if (empty($command['color'])) {
                $command['color'] = self::getColor($commands);
            }
        }

        unset($command);

        return $commands;
    }

    /**
     * Get the next unused palette color, cycling once exhausted.
     *
     * @param array<int, ProcessArray> $commands Processes.
     * @return string Hex color.
     */
    protected static function getColor(array $commands): string
    {
        $colors = array_map(fn(ProcessColor $color): string => $color->value, ProcessColor::cases());
        $existing = array_values(array_filter(array_column($commands, 'color')));
        $available = array_values(array_diff($colors, $existing));

        return $available[0] ?? $colors[self::$colorCount++ % count($colors)];
    }

    /**
     * Resolve the first external caller frame from a backtrace.
     *
     * @param array<int, array{file?: string, line?: int, class?: string, function?: string}> $trace Backtrace.
     * @return array{file?: string, line?: int, class?: string, function?: string}
     */
    protected static function resolveSource(array $trace): array
    {
        foreach ($trace as $frame) {
            if (($frame['file'] ?? null) === __FILE__) {
                continue;
            }

            if (($frame['class'] ?? null) === self::class) {
                continue;
            }

            return $frame;
        }

        return [];
    }

    /**
     * Determine registration priority from a backtrace.
     *
     * Calls from `registerDefaults()` are defaults; calls from inside the
     * vendor directory are vendor commands; everything else is userland.
     *
     * @param array<int, array{file?: string, line?: int, class?: string, function?: string}> $trace Backtrace.
     * @return int One of the Process::PRIORITY_* constants.
     */
    protected static function resolvePriority(array $trace): int
    {
        $vendorPath = self::rootPath() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            $class = $frame['class'] ?? null;

            if ($file === __FILE__) {
                continue;
            }

            if ($class === self::class && ($frame['function'] ?? null) === 'registerDefaults') {
                return Process::PRIORITY_DEFAULT;
            }

            if ($file === null && $class !== null && class_exists($class)) {
                $file = (new ReflectionClass($class))->getFileName();
            }

            if (!is_string($file)) {
                continue;
            }

            if ($file === '') {
                continue;
            }

            if (!str_starts_with($file, $vendorPath)) {
                return Process::PRIORITY_USERLAND;
            }
        }

        return Process::PRIORITY_VENDOR;
    }

    /**
     * Resolve the NodePackageManager instance.
     *
     * @return \Crustum\DevConsole\Service\NodePackageManager
     */
    protected static function getPackageManager(): NodePackageManager
    {
        if (!self::$packageManager instanceof NodePackageManager) {
            self::$packageManager = new NodePackageManager(self::rootPath());
        }

        return self::$packageManager;
    }

    /**
     * Whether the current SAPI is a console.
     *
     * @return bool
     */
    protected static function runningInConsole(): bool
    {
        if ((bool)Configure::read('DevConsole.forceRegister')) {
            return true;
        }

        return in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
    }
}
