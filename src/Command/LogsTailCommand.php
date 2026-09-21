<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\DevConsole\LogTail\CliPrinter;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\Options;
use Crustum\DevConsole\LogTail\Parser;
use Crustum\DevConsole\LogTail\Reader;
use Crustum\DevConsole\LogTail\SessionFiles;
use Crustum\DevConsole\LogTail\Severity;
use Crustum\DevConsole\LogTail\TailEngine;
use Crustum\DevConsole\Process\Processes;
use Override;

/**
 * Tail the application logs.
 *
 * Creates a session file, then follows it while every application
 * process running the tail engine streams captured records to it. Usage:
 * `bin/cake logs tail [--level=error] [--scope=payments] [--filter=...] [--message=...]`
 */
class LogsTailCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Tail the application logs';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('filter', [
                'help' => 'Filter the logs by the given value',
            ])
            ->addOption('message', [
                'help' => 'Filter the logs by the given message',
            ])
            ->addOption('level', [
                'help' => 'Filter the logs by the given minimum level',
            ])
            ->addOption('scope', [
                'help' => 'Filter the logs by comma-separated scopes',
            ])
            ->addOption('timeout', [
                'default' => '3600',
                'help' => 'The maximum execution time in seconds',
            ])
            ->addOption('lines', [
                'default' => '0',
                'help' => 'Exit after printing this many lines (0 for unlimited)',
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
        $level = $args->getOption('level');
        if (is_string($level) && $level !== '' && !Severity::known($level)) {
            $io->error(sprintf('Invalid --level "%s".', $level));

            return static::CODE_ERROR;
        }

        $io->out('<info>Tailing application logs. Press Ctrl+C to exit.</info>');
        $io->out('<comment>Use -v to show more details.</comment>');

        TailEngine::register();

        $sessions = SessionFiles::configured();
        $file = $sessions->create();
        $stopped = false;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use (&$stopped): void {
                $stopped = true;
            });
            pcntl_signal(SIGTERM, function () use (&$stopped): void {
                $stopped = true;
            });
        }

        try {
            $options = Options::fromArguments($args);
            $parser = new Parser();
            $printer = new CliPrinter($io, Processes::rootPath());
            $printed = 0;

            (new Reader($file))->follow(
                function (string $line) use ($options, $parser, $printer, $io, &$printed): bool {
                    $entry = $parser->parse($line);

                    if (!$entry instanceof LogEntry) {
                        if (trim($line) !== '') {
                            $io->warning('Skipped a malformed log line.');
                        }

                        return true;
                    }

                    if (!$options->accepts($entry)) {
                        return true;
                    }

                    $printer->print($entry);
                    $printed++;
                    if ($options->lines() === 0) {
                        return true;
                    }

                    return $printed < $options->lines();
                },
                $options->timeout(),
                0,
                static fn(): bool => $stopped,
            );

            if (!$stopped) {
                $io->out('<comment>Maximum execution time exceeded.</comment>');
            }

            return static::CODE_SUCCESS;
        } finally {
            $sessions->destroy($file);
        }
    }
}
