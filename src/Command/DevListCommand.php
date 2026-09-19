<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\DevConsole\Process\Process;
use Crustum\DevConsole\Process\Processes;
use Override;

/**
 * List the registered dev processes.
 *
 * Port of `Illuminate\Foundation\Console\DevListCommand`.
 *
 * Usage: `bin/cake dev_list [--json] [--filter=...] [--except-vendor] [--only-vendor]`
 *
 * @phpstan-import-type ProcessArray from \Crustum\DevConsole\Process\Processes
 */
class DevListCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'List the registered dev processes';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('json', [
                'boolean' => true,
                'help' => 'Output the dev process list as JSON',
            ])
            ->addOption('filter', [
                'help' => 'Filter the dev processes by name or command',
            ])
            ->addOption('except-vendor', [
                'boolean' => true,
                'help' => 'Do not display dev processes registered by vendor packages',
            ])
            ->addOption('only-vendor', [
                'boolean' => true,
                'help' => 'Only display dev processes registered by vendor packages',
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
        $devCommands = $this->filterCommands(Processes::commands(), $args);

        if ($devCommands === []) {
            if ($args->getOption('json')) {
                $io->out('[]');

                return $this->isFiltering($args) ? static::CODE_ERROR : static::CODE_SUCCESS;
            }

            $io->out('');

            if ($this->isFiltering($args)) {
                $io->error("Your application doesn't have any dev processes matching the given criteria.");

                return static::CODE_ERROR;
            }

            $io->warning("Your application doesn't have any dev processes.");

            return static::CODE_SUCCESS;
        }

        $withSource = array_map(
            fn(array $command): array => array_merge($command, [
                'source' => $this->formatSource($command['source']),
            ]),
            $devCommands,
        );

        if ($args->getOption('json')) {
            $io->out((string)json_encode(array_values($withSource)));

            return static::CODE_SUCCESS;
        }

        $io->out('');

        $names = array_column($withSource, 'name');
        $widths = array_map(mb_strlen(...), $names);
        $longestName = max($widths);

        foreach ($withSource as $devCommand) {
            $label = str_pad($devCommand['name'], $longestName);
            $io->out("  {$label} {$devCommand['command']} <comment>{$devCommand['source']}</comment>");
        }

        $count = count($withSource);
        $io->out('');
        $io->out("Showing [{$count}] dev " . ($count === 1 ? 'process' : 'processes'));
        $io->out('');

        return static::CODE_SUCCESS;
    }

    /**
     * Whether any filtering option is active.
     *
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @return bool
     */
    protected function isFiltering(Arguments $args): bool
    {
        return (bool)$args->getOption('filter')
            || (bool)$args->getOption('except-vendor')
            || (bool)$args->getOption('only-vendor');
    }

    /**
     * Filter processes by the given options.
     *
     * @param array<int, ProcessArray> $commands Processes.
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @return array<int, ProcessArray>
     */
    protected function filterCommands(array $commands, Arguments $args): array
    {
        $filter = $args->getOption('filter');

        if (is_string($filter) && $filter !== '') {
            $commands = array_filter(
                $commands,
                fn(array $command): bool => str_contains($command['name'], $filter)
                    || str_contains($command['command'], $filter),
            );
        }

        if ($args->getOption('except-vendor')) {
            $commands = array_filter(
                $commands,
                fn(array $command): bool => !$this->isVendorCommand($command),
            );
        }

        if ($args->getOption('only-vendor')) {
            $commands = array_filter(
                $commands,
                $this->isVendorCommand(...),
            );
        }

        return array_values($commands);
    }

    /**
     * Whether the process was registered by a vendor package.
     *
     * @param array{priority: int} $command Process.
     * @return bool
     */
    protected function isVendorCommand(array $command): bool
    {
        return $command['priority'] === Process::PRIORITY_VENDOR;
    }

    /**
     * Format source info for display, relative to the app root.
     *
     * @param array{file?: string, line?: int, class?: string, function?: string} $source Source info.
     * @return string Formatted source, e.g. `src/Application.php:42` or `Class@method`.
     */
    protected function formatSource(array $source): string
    {
        $class = $source['class'] ?? null;
        $function = $source['function'] ?? null;

        if (is_string($class) && $class !== '') {
            return "{$class}@{$function}";
        }

        $file = $source['file'] ?? null;
        $line = $source['line'] ?? null;

        if (!is_string($file) || $file === '') {
            return '';
        }

        $root = Processes::rootPath() . DIRECTORY_SEPARATOR;

        if (str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }

        if ($line !== null) {
            return "{$file}:{$line}";
        }

        return $file;
    }
}
