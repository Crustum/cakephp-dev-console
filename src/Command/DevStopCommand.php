<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\DevConsole\Process\Processes;
use Override;

/**
 * Stop a supervised `dev` run left behind by an unclean shutdown.
 *
 * Reads the pid file written by `dev`, asks the tree to terminate
 * gracefully, waits, then force-kills leftovers — the same graceful-first
 * sequence as `Monitor\Worker\ProcessTerminator`.
 *
 * Usage: `bin/cake dev stop [--timeout=5]`
 */
class DevStopCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Stop a supervised dev run';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('timeout', [
                'help' => 'Seconds to wait for graceful shutdown before force-killing',
                'default' => '5',
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
        $pid = Processes::runningPid();

        if ($pid === null) {
            $io->out('No dev processes are running.');

            return static::CODE_SUCCESS;
        }

        $timeout = $args->getOption('timeout');
        $seconds = is_numeric($timeout) ? max(0, (int)$timeout) : 5;

        Processes::killTree($pid, false);
        $this->waitWhileRunning($pid, $seconds);

        if (Processes::isPidRunning($pid)) {
            $io->warning("PID {$pid} did not stop gracefully, force-killing...");
            Processes::killTree($pid, true);
            $this->waitWhileRunning($pid, 2.0);
        }

        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }

        if (Processes::isPidRunning($pid)) {
            $io->error("Could not stop PID {$pid}.");

            return static::CODE_ERROR;
        }

        $io->success("Stopped dev processes (PID {$pid}).");

        return static::CODE_SUCCESS;
    }

    /**
     * Wait while a pid reports as running, up to a timeout.
     *
     * @param int $pid Process id.
     * @param float $seconds Maximum seconds to wait.
     * @return void
     */
    protected function waitWhileRunning(int $pid, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (Processes::isPidRunning($pid) && microtime(true) < $deadline) {
            usleep(200000);
        }
    }
}
