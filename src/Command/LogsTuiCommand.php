<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Crustum\DevConsole\LogTail\ServeEvent;
use Crustum\DevConsole\Process\Processes;
use Crustum\DevConsole\Service\NodePackageManager;
use Override;
use SignalHandler\Command\Trait\SignalHandlerTrait;

/**
 * Launch the log TUI host (`@crustum/log-tui`, OpenTUI).
 *
 * Mirrors `DevCommand`'s runner resolution: an explicit `DevConsole.logsTuiPath` config
 * value (a checkout directory or the `cli.js` file itself — e.g. the
 * `workspace/log-tui` checkout during development) wins, otherwise the published
 * npm package is executed via the detected package manager.
 *
 * The host spawns `logs serve` itself; the exact serve invocation is handed over
 * in the `CAKE_LOGS_SERVE_COMMAND` environment variable (fallback inside the host
 * is `php bin/cake.php logs serve`). Single interactive session: no pid file.
 *
 * Usage: `bin/cake logs tui [--tail=200] [--sources=cake_live,cake_file]`
 */
class LogsTuiCommand extends Command
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
        return 'Browse application logs in a terminal UI';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('tail', [
                'default' => '200',
                'help' => 'Backfill lines per source',
            ])
            ->addOption('sources', [
                'help' => 'Comma-separated sources (default: cake_live,cake_file)',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'help' => 'Print the TUI command without executing it',
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
        $tail = $args->getOption('tail');

        if (!is_string($tail) || !ctype_digit($tail)) {
            $io->error('Invalid --tail. Use a non-negative integer.');

            return static::CODE_ERROR;
        }

        $sources = $this->parseSources($args->getOption('sources'));

        if ($sources === []) {
            $io->error('Invalid --sources. List at least one source.');

            return static::CODE_ERROR;
        }

        $packageManager = new NodePackageManager(Processes::rootPath());
        $command = $this->buildTuiCommand($packageManager, (int)$tail, $sources);
        $serveCommand = $this->buildServeCommand((int)$tail, $sources);

        if ($args->getOption('dry-run')) {
            $io->out($command);

            return static::CODE_SUCCESS;
        }

        if (!$this->runtimeAvailable()) {
            $io->error('No Node.js or Bun runtime found. The TUI needs Bun >= 1.3 or Node >= 26.4; use `bin/cake logs tail` instead — it is PHP-only and always works.');

            return static::CODE_ERROR;
        }

        $this->setServeEnv($serveCommand);

        if (function_exists('pcntl_exec') && PHP_OS_FAMILY !== 'Windows') {
            pcntl_exec('/usr/bin/env', ['sh', '-c', $command]);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->passthruConsole($command);
        }

        passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Build the shell command that runs the TUI host.
     *
     * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
     * @param int $tail Backfill lines per source.
     * @param list<string> $sources Sources.
     * @return string Runnable shell command.
     */
    protected function buildTuiCommand(NodePackageManager $packageManager, int $tail, array $sources): string
    {
        $command = $this->resolveLogsTuiRunner($packageManager);
        $command .= ' --tail=' . $tail;

        return $command . ' --sources=' . escapeshellarg(implode(',', $sources));
    }

    /**
     * Build the serve invocation handed to the host via `CAKE_LOGS_SERVE_COMMAND`.
     *
     * @param int $tail Backfill lines per source.
     * @param list<string> $sources Sources.
     * @return string Runnable shell command.
     */
    protected function buildServeCommand(int $tail, array $sources): string
    {
        $cake = Processes::rootPath() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'cake.php';

        if (!is_file($cake)) {
            $cake = 'bin' . DIRECTORY_SEPARATOR . 'cake.php';
        }

        return escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($cake)
            . ' logs serve --tail=' . $tail
            . ' --sources=' . escapeshellarg(implode(',', $sources));
    }

    /**
     * Resolve how to invoke the TUI host.
     *
     * Prefers an explicit `DevConsole.logsTuiPath` config value (a log-tui checkout
     * directory or the `cli.js` file itself, run directly with `node`), and otherwise
     * uses the published `@crustum/log-tui` npm package via the detected
     * package manager. Development use:
     * `Configure::write('DevConsole.logsTuiPath', '<plugin>/workspace/log-tui')`
     * after `npm install && npm run build` in that checkout.
     *
     * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
     * @return string Runner prefix, e.g. `node "/path/cli.js"` or `npx @crustum/log-tui`.
     */
    protected function resolveLogsTuiRunner(NodePackageManager $packageManager): string
    {
        /** @var mixed $configured */
        $configured = Configure::read('DevConsole.logsTuiPath');

        if (is_string($configured) && $configured !== '') {
            $cli = $this->logsTuiCliIn($configured);

            if ($cli !== null) {
                return 'node ' . escapeshellarg($cli);
            }
        }

        return $packageManager->getExecCommand('@crustum/log-tui');
    }

    /**
     * Find the log-tui CLI entry inside a directory or path.
     *
     * Accepts either the `cli.js` file itself or a directory containing
     * `dist/cli.js` (a log-tui checkout).
     *
     * @param string $path Directory or file path.
     * @return string|null Absolute path to `cli.js`, if present.
     */
    protected function logsTuiCliIn(string $path): ?string
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
     * Export the serve command for the host process.
     *
     * `putenv` covers the `pcntl_exec` path; the explicit environment array in
     * `passthruConsole()` covers the Windows `proc_open` path.
     *
     * @param string $serveCommand Serve shell command.
     * @return void
     */
    protected function setServeEnv(string $serveCommand): void
    {
        putenv('CAKE_LOGS_SERVE_COMMAND=' . $serveCommand);
        $_SERVER['CAKE_LOGS_SERVE_COMMAND'] = $serveCommand;
        $_ENV['CAKE_LOGS_SERVE_COMMAND'] = $serveCommand;
    }

    /**
     * Whether a JS runtime binary is reachable (version is checked by the host itself).
     *
     * @return bool True when `node` or `bun` resolves on PATH, or exec is unavailable to check.
     */
    protected function runtimeAvailable(): bool
    {
        if (!function_exists('exec')) {
            return true;
        }

        $probe = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';

        foreach (['node', 'bun'] as $binary) {
            set_error_handler(static fn(): bool => true);
            try {
                exec($probe . ' ' . escapeshellarg($binary) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $lines, $code);
            } finally {
                restore_error_handler();
            }

            if ($code === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run a command with the parent console as its stdio.
     *
     * Same reasoning as `DevCommand::passthruConsole()`: PHP's `passthru()` on Windows
     * pumps stdio through pipes, so the TUI child would see `isTTY === false` and refuse
     * to start. Handing `STDIN`/`STDOUT`/`STDERR` to `proc_open()` attaches the child to
     * the real console instead. No pid file — this is a single interactive session.
     *
     * @param string $command Shell command.
     * @return int Exit code.
     */
    protected function passthruConsole(string $command): int
    {
        $env = [];

        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $env[$key] = (string)$value;
            }
        }

        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $env);

        if (!is_resource($process)) {
            passthru($command, $exitCode);

            return $exitCode;
        }

        $status = proc_get_status($process);
        $this->childPid = $status['pid'];
        $this->interruptCount = 0;

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
            proc_close($process);
        }
    }

    /**
     * Handle a console interrupt while supervising the TUI.
     *
     * The first Ctrl+C is survived so the TUI can shut down gracefully (it owns
     * shutdown and tells `logs serve` to exit); a repeated interrupt force-kills
     * the whole tree.
     *
     * @return void
     */
    protected function onConsoleInterrupt(): void
    {
        $this->interruptCount++;

        if ($this->interruptCount > 1) {
            Processes::killTree($this->childPid, true);
        }
    }

    /**
     * Parse the `--sources` option.
     *
     * @param string|bool|null $value Raw option value.
     * @return list<string>
     */
    protected function parseSources(string|bool|null $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return ServeEvent::SOURCES;
        }

        $sources = array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn(string $source): bool => $source !== '',
        ));

        return $sources === [] ? ServeEvent::SOURCES : $sources;
    }
}
