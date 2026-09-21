<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Crustum\DevConsole\Process\Processes;
use Crustum\DevConsole\Process\ProcessMode;
use Crustum\DevConsole\Service\NodePackageManager;
use Override;
use SignalHandler\Command\Trait\SignalHandlerTrait;

/**
 * Run the registered dev processes.
 *
 * Port of `Illuminate\Foundation\Console\DevCommand`. Collects the processes
 * from {@see \Crustum\DevConsole\Process\Processes} and delegates rendering and
 * process supervision to multiplex (Unix) or `concurrently` (Windows).
 *
 * Usage: `bin/cake dev [--stream|--tabs|--inline] [--runner=multiplex|concurrently]`
 *
 * @phpstan-import-type ProcessArray from \Crustum\DevConsole\Process\Processes
 */
class DevCommand extends Command
{
    use SignalHandlerTrait;

    /**
     * Consecutive console interrupts received while supervising.
     *
     * @var int
     */
    protected int $interruptCount = 0;

    /**
     * Supervised child pid for force-kill on repeated interrupts.
     *
     * @var int
     */
    protected int $childPid = 0;

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Run the dev processes';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('stream', [
                'short' => 's',
                'boolean' => true,
                'help' => 'Start in stream mode',
            ])
            ->addOption('tabs', [
                'short' => 't',
                'boolean' => true,
                'help' => 'Start in tabs mode',
            ])
            ->addOption('inline', [
                'short' => 'i',
                'boolean' => true,
                'help' => 'Print output inline instead of rendering the TUI (the default when not a TTY)',
            ])
            ->addOption('timestamps', [
                'boolean' => true,
                'help' => 'Display timestamps on each output line',
            ])
            ->addOption('no-restart', [
                'boolean' => true,
                'help' => 'Disable auto-restart on crash',
            ])
            ->addOption('json', [
                'boolean' => true,
                'help' => 'Emit newline-delimited JSON events. Implies --inline',
            ])
            ->addOption('buffer-size', [
                'help' => 'Set the max lines per command buffer',
            ])
            ->addOption('stream-buffer-size', [
                'help' => 'Set the max lines in the stream buffer',
            ])
            ->addOption('runner', [
                'help' => 'Process runner: multiplex (tabbed TUI) or concurrently (merged output). Defaults to multiplex, except on Windows where it defaults to concurrently.',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'help' => 'Print the runner command without executing it',
            ]);
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @param \Cake\Console\ConsoleIo $io Console I/O.
     * @return int Exit code.
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $devCommands = Processes::commands();

        if ($devCommands === []) {
            $io->error('Your application does not have any dev processes.');

            return static::CODE_ERROR;
        }

        $packageManager = new NodePackageManager(Processes::rootPath());
        $runner = $this->resolveRunner($args);

        if ($runner === null) {
            $io->error('Invalid --runner. Use "multiplex" or "concurrently".');

            return static::CODE_ERROR;
        }

        if ($args->getOption('dry-run')) {
            Processes::runningPid();
            $io->out($this->dryRunCommand($devCommands, $packageManager, $args, $runner));

            return static::CODE_SUCCESS;
        }

        $runningPid = Processes::runningPid();

        if ($runningPid !== null) {
            $commandLine = Processes::pidCommandLine($runningPid);
            $detail = '';

            if (is_string($commandLine) && trim($commandLine) !== '') {
                $detail = ': ' . mb_substr(trim(preg_replace('/\s+/', ' ', $commandLine) ?? ''), 0, 100);
            }

            $io->error("Dev processes are already running (PID {$runningPid}{$detail}). Stop them with `ck dev stop` first.");

            return static::CODE_ERROR;
        }

        if ($runner === 'concurrently') {
            return $this->runViaConcurrently($devCommands, $packageManager, $args, $io);
        }

        return $this->runViaMultiplex($devCommands, $packageManager, $args);
    }

    /**
     * Build (without executing) the exact shell command a run would use.
     *
     * @param array<int, ProcessArray> $devCommands Processes.
     * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @param string $runner Resolved runner name.
     * @return string Runnable shell command.
     */
    protected function dryRunCommand(
        array $devCommands,
        NodePackageManager $packageManager,
        Arguments $args,
        string $runner,
    ): string {
        $options = $this->commandOptions($args);

        if ($runner === 'concurrently') {
            return $packageManager->getExecCommand($this->buildConcurrentlyCommand($devCommands, $options));
        }

        return $this->buildMultiplexCommand($devCommands, $packageManager, $options);
    }

    /**
     * Resolve which process runner to use.
     *
     * CLI `--runner` wins over the `DevConsole.runner` config value, which
     * wins over the OS default (concurrently on Windows, multiplex elsewhere).
     *
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @return string|null `multiplex`, `concurrently`, or null when invalid.
     */
    protected function resolveRunner(Arguments $args): ?string
    {
        $runner = $args->getOption('runner');

        if ($runner === null || $runner === false) {
            $configured = Configure::read('DevConsole.runner');

            $runner = is_string($configured) && $configured !== '' && $configured !== 'auto'
                ? $configured
                : null;
        }

        if ($runner === null) {
            return PHP_OS_FAMILY === 'Windows' ? 'concurrently' : 'multiplex';
        }

        if ($runner !== 'multiplex' && $runner !== 'concurrently') {
            return null;
        }

        return $runner;
    }

    /**
     * Run the dev processes via multiplex.
     *
     * @param array<int, ProcessArray> $devCommands Processes.
     * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @return int Exit code.
     */
    protected function runViaMultiplex(
        array $devCommands,
        NodePackageManager $packageManager,
        Arguments $args,
    ): int {
        $command = $this->buildMultiplexCommand(
            $devCommands,
            $packageManager,
            $this->commandOptions($args),
        );

        if (function_exists('pcntl_exec') && PHP_OS_FAMILY !== 'Windows') {
            $this->writePidFile($this->currentPid());
            pcntl_exec('/usr/bin/env', ['sh', '-c', $command]);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->passthruConsole($command, true);
        }

        $this->writePidFile($this->currentPid());

        try {
            passthru($command, $exitCode);

            return $exitCode;
        } finally {
            $this->removePidFile();
        }
    }

    /**
     * Run a command with the parent console as its stdio.
     *
     * PHP's `passthru()` on Windows pumps stdio through pipes, so interactive
     * children (like the multiplex TUI) see `isTTY === false` and degrade to
     * inline output. Handing `STDIN`/`STDOUT`/`STDERR` to `proc_open()`
     * attaches the child to the real console instead.
     *
     * Shutdown is owned by the `skie/signal_handler` plugin: the first Ctrl+C
     * lets the child shut down gracefully (PHP survives it instead of dying
     * on the spot, which is what orphaned whole process trees before), while
     * a second Ctrl+C force-kills the entire tree via `taskkill /T /F`.
     *
     * @param string $command Shell command.
     * @param bool $trackPid Write the supervised pid to the pid file.
     * @return int Exit code.
     */
    protected function passthruConsole(string $command, bool $trackPid = false): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

        if (!is_resource($process)) {
            passthru($command, $exitCode);

            return $exitCode;
        }

        $status = proc_get_status($process);
        $this->childPid = $status['pid'];
        $this->interruptCount = 0;

        if ($trackPid) {
            $this->childPid = $this->resolveSupervisedPid($this->childPid);
            $this->writePidFile($this->childPid);
        }

        $this->bindGracefulTermination(function (): void {
            $this->onConsoleInterrupt();
        });

        try {
            do {
                usleep(200000);
                $status = proc_get_status($process);
            } while ($status['running']);

            return $status['exitcode'];
        } finally {
            $this->unbindSignals();
            $this->childPid = 0;

            if ($trackPid) {
                $this->removePidFile();
            }

            proc_close($process);
        }
    }

    /**
     * Handle a console interrupt while supervising the runner.
     *
     * The first interrupt asks the tree to terminate gracefully (`taskkill /PID /T`, no `/F`) so
     * workers run their cleanup handlers; a repeated interrupt force-kills
     * the whole tree (`/T /F`).
     *
     * @return void
     */
    protected function onConsoleInterrupt(): void
    {
        $this->interruptCount++;

        Processes::killTree($this->childPid, $this->interruptCount > 1);
    }

    /**
     * Resolve the supervised runner pid below a wrapper pid.
     *
     * PHP spawns console children via a `cmd.exe` wrapper; the runner
     * (node) is its direct child. Falls back to the wrapper pid when the
     * child cannot be resolved (non-Windows, missing exec, slow spawn).
     *
     * @param int $wrapperPid Wrapper process id from proc_open().
     * @return int Supervised pid.
     */
    protected function resolveSupervisedPid(int $wrapperPid): int
    {
        if ($wrapperPid <= 0 || PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
            return $wrapperPid;
        }

        for ($i = 0; $i < 30; $i++) {
            $nodePid = $this->findChildPid($wrapperPid, 'node.exe');

            if ($nodePid !== null) {
                return $nodePid;
            }

            usleep(100000);
        }

        return $wrapperPid;
    }

    /**
     * Find a direct child process by executable name.
     *
     * Wmic CSV columns: Node, Name, ProcessId.
     *
     * @param int $parentPid Parent process id.
     * @param string $name Executable name, e.g. `node.exe`.
     * @return int|null Child pid, or null when not found.
     */
    protected function findChildPid(int $parentPid, string $name): ?int
    {
        if ($parentPid <= 0 || PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
            return null;
        }

        exec(
            'wmic process where "ParentProcessId=' . $parentPid . '" get Name,ProcessId /FORMAT:CSV 2> NUL',
            $lines,
        );

        foreach ($lines as $line) {
            $parts = str_getcsv(trim($line), ',', '"', '');

            if (count($parts) !== 3) {
                continue;
            }

            if (strtolower((string)$parts[1]) === strtolower($name) && is_numeric($parts[2])) {
                return (int)$parts[2];
            }
        }

        return null;
    }

    /**
     * Get the current process id, or zero when unavailable.
     *
     * @return int
     */
    protected function currentPid(): int
    {
        $pid = getmypid();

        return $pid === false ? 0 : $pid;
    }

    /**
     * Write the supervised pid to the pid file.
     *
     * @param int $pid Process id.
     * @return void
     */
    protected function writePidFile(int $pid): void
    {
        if ($pid > 0) {
            file_put_contents(Processes::pidFile(), (string)$pid);
        }
    }

    /**
     * Remove the supervised pid file, if present.
     *
     * @return void
     */
    protected function removePidFile(): void
    {
        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Build the shell command that runs multiplex with the given processes.
     *
     * Argument format is `name@color,command` (only the first comma is
     * structural), each escaped as a single shell word.
     *
     * @param array<int, ProcessArray> $devCommands Processes.
     * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
     * @param array{mode: \Crustum\DevConsole\Process\ProcessMode, timestamps: bool, noRestart: bool, json: bool, bufferSize: int|null, streamBufferSize: int|null} $options Resolved options.
     * @return string Runnable shell command.
     */
    protected function buildMultiplexCommand(
        array $devCommands,
        NodePackageManager $packageManager,
        array $options,
    ): string {
        $procArgs = array_map(
            fn(array $devCommand): string => escapeshellarg(
                $devCommand['name'] . '@' . $devCommand['color'] . ',' . $devCommand['command'],
            ),
            $devCommands,
        );

        $flags = [];

        if ($options['mode'] === ProcessMode::STREAM) {
            $flags[] = '--stream';
        } elseif ($options['mode'] === ProcessMode::INLINE) {
            $flags[] = '--inline';
        }

        if ($options['timestamps']) {
            $flags[] = '--timestamps';
        }

        if ($options['noRestart']) {
            $flags[] = '--no-restart';
        }

        if ($options['json']) {
            $flags[] = '--json';
        }

        if ($options['bufferSize'] !== null) {
            $flags[] = '--buffer-size=' . escapeshellarg((string)$options['bufferSize']);
        }

        if ($options['streamBufferSize'] !== null) {
            $flags[] = '--stream-buffer-size=' . escapeshellarg((string)$options['streamBufferSize']);
        }

        $command = $this->resolveMultiplexRunner($packageManager);
        $command .= ' --title ' . escapeshellarg($this->multiplexTitle());

        if ($flags !== []) {
            $command .= ' ' . implode(' ', $flags);
        }

        return $command . ' ' . implode(' ', $procArgs);
    }

    /**
     * Resolve how to invoke multiplex.
     *
     * Prefers an explicit `DevConsole.multiplexPath` config value (a
     * multiplex checkout directory or the `cli.js` file itself, run
     * directly with `node`), and otherwise uses the published
     * `@crustum/multiplex` npm package via the detected package manager.
     *
     * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
     * @return string Runner prefix, e.g. `node "/path/cli.js"` or `npx @crustum/multiplex`.
     */
    protected function resolveMultiplexRunner(NodePackageManager $packageManager): string
    {
        /** @var mixed $configured */
        $configured = Configure::read('DevConsole.multiplexPath');

        if (is_string($configured) && $configured !== '') {
            $cli = $this->multiplexCliIn($configured);

            if ($cli !== null) {
                return 'node ' . escapeshellarg($cli);
            }
        }

        return $packageManager->getExecCommand('@crustum/multiplex');
    }

    /**
     * Find the multiplex CLI entry inside a directory or path.
     *
     * Accepts either the `cli.js` file itself or a directory containing
     * `dist/cli.js` (a multiplex checkout).
     *
     * @param string $path Directory or file path.
     * @return string|null Absolute path to `cli.js`, if present.
     */
    protected function multiplexCliIn(string $path): ?string
    {
        if (is_file($path) && basename($path) === 'cli.js') {
            return $path;
        }

        $cli = rtrim($path, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'dist'
            . DIRECTORY_SEPARATOR . 'cli.js';

        if (is_file($cli)) {
            return $cli;
        }

        return null;
    }

    /**
     * Build the multiplex `--title` value.
     *
     * @return string Title, e.g. `cake dev · my-app`.
     */
    protected function multiplexTitle(): string
    {
        /** @var mixed $appName */
        $appName = Configure::read('DevConsole.appName');

        if (!is_string($appName) || $appName === '') {
            $appName = basename(Processes::rootPath());
        }

        return 'cake dev · ' . $appName;
    }

    /**
     * Run the dev processes via `concurrently`.
     *
     * Prints a command legend, then streams merged color-coded output. On
     * Windows the runner goes through the console-attached supervisor (pid
     * tracking plus signal handling) like the multiplex path.
     *
     * @param array<int, ProcessArray> $devCommands Processes.
     * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @param \Cake\Console\ConsoleIo $io Console I/O.
     * @return int Exit code.
     */
    protected function runViaConcurrently(
        array $devCommands,
        NodePackageManager $packageManager,
        Arguments $args,
        ConsoleIo $io,
    ): int {
        $command = $packageManager->getExecCommand(
            $this->buildConcurrentlyCommand($devCommands, $this->commandOptions($args)),
        );

        $names = array_column($devCommands, 'name');
        $widths = array_map(mb_strlen(...), $names);
        $longestName = $widths === [] ? 0 : max($widths);

        foreach ($devCommands as $devCommand) {
            $padding = str_repeat(' ', $longestName - mb_strlen($devCommand['name']) + 1);
            $io->out("[{$devCommand['name']}]{$padding}{$devCommand['command']}");
        }

        $io->out('');

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->passthruConsole($command, true);
        }

        passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Build the `concurrently` invocation for the given processes.
     *
     * @param array<int, ProcessArray> $devCommands Processes.
     * @param array{mode: \Crustum\DevConsole\Process\ProcessMode, timestamps: bool, noRestart: bool, json: bool, bufferSize: int|null, streamBufferSize: int|null} $options Resolved options.
     * @return string Command body (without the package-manager exec prefix).
     */
    protected function buildConcurrentlyCommand(array $devCommands, array $options): string
    {
        $names = array_column($devCommands, 'name');
        $commands = array_column($devCommands, 'command');
        $colors = array_column($devCommands, 'color');

        $quoted = array_map(
            fn(string $command): string => '"' . str_replace('"', '""', $command) . '"',
            $commands,
        );

        $command = sprintf(
            'concurrently -c "%s" %s --names=%s',
            implode(',', $colors),
            implode(' ', $quoted),
            implode(',', $names),
        );

        if (!$options['noRestart']) {
            $command .= ' --restart-tries=5 --restart-after=1000';
        } else {
            $command .= ' --kill-others-on-fail';
        }

        if ($options['timestamps']) {
            $command .= ' --timestamp-format="HH:mm:ss" -p "{time} [{name}]"';
        }

        return $command;
    }

    /**
     * Resolve CLI options against the Processes configuration.
     *
     * CLI flags win over `Processes` configuration, which wins over defaults.
     *
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @return array{mode: \Crustum\DevConsole\Process\ProcessMode, timestamps: bool, noRestart: bool, json: bool, bufferSize: int|null, streamBufferSize: int|null}
     */
    protected function commandOptions(Arguments $args): array
    {
        if ($args->getOption('tabs')) {
            $mode = ProcessMode::TABS;
        } elseif ($args->getOption('stream')) {
            $mode = ProcessMode::STREAM;
        } elseif ($args->getOption('inline') || $args->getOption('json')) {
            $mode = ProcessMode::INLINE;
        } else {
            $mode = Processes::mode();
        }

        return [
            'mode' => $mode,
            'timestamps' => (bool)$args->getOption('timestamps') || Processes::shouldIncludeTimestamps(),
            'noRestart' => (bool)$args->getOption('no-restart') || !Processes::shouldAutoRestart(),
            'json' => (bool)$args->getOption('json'),
            'bufferSize' => $this->intOption($args->getOption('buffer-size')) ?? Processes::getBufferSize(),
            'streamBufferSize' => $this->intOption($args->getOption('stream-buffer-size'))
                ?? Processes::getStreamBufferSize(),
        ];
    }

    /**
     * Narrow a CLI option value to a non-negative int.
     *
     * Non-numeric input (missing value, flag reuse) falls back to null so the
     * caller uses the configured default instead of a bogus zero.
     *
     * @param string|bool|null $value Raw option value.
     * @return int|null
     */
    protected function intOption(bool|string|null $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return (int)$value;
    }
}
