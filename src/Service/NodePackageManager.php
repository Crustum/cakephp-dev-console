<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Service;

/**
 * Detects the Node package manager in use and builds its CLI commands.
 *
 * Port of `Illuminate\Support\NodePackageManager`.
 */
class NodePackageManager
{
    /**
     * Known managers: lock file, exec prefix, run prefix.
     *
     * @var array<string, array{lock: string, exec: string, run: string}>
     */
    protected const MANAGERS = [
        'bun' => ['lock' => 'bun.lockb', 'exec' => 'bunx', 'run' => 'bun run'],
        'pnpm' => ['lock' => 'pnpm-lock.yaml', 'exec' => 'pnpm dlx', 'run' => 'pnpm run'],
        'yarn' => ['lock' => 'yarn.lock', 'exec' => 'yarn dlx', 'run' => 'yarn'],
    ];

    /**
     * Detected manager name.
     *
     * @var string|null
     */
    protected ?string $manager = null;

    /**
     * @param string|null $rootPath Application root for lock-file detection.
     */
    public function __construct(
        protected ?string $rootPath = null,
    ) {
    }

    /**
     * Build the command to execute a package binary.
     *
     * @param string $command Binary invocation, e.g. `@crustum/multiplex ...`.
     * @return string Full shell command, e.g. `npx @crustum/multiplex ...`.
     */
    public function getExecCommand(string $command): string
    {
        return $this->execPrefix() . ' ' . $command;
    }

    /**
     * Build the command to run a package.json script.
     *
     * @param string $command Script name, e.g. `dev`.
     * @return string Full shell command, e.g. `npm run dev`.
     */
    public function getRunCommand(string $command): string
    {
        return $this->runPrefix() . ' ' . $command;
    }

    /**
     * Get the detected package manager name.
     *
     * @return string One of `bun`, `pnpm`, `yarn`, `npm`.
     */
    public function name(): string
    {
        return $this->manager ??= $this->detect();
    }

    /**
     * Get the exec prefix for the detected manager.
     *
     * @return string
     */
    public function execPrefix(): string
    {
        return self::MANAGERS[$this->name()]['exec'] ?? 'npx';
    }

    /**
     * Get the run prefix for the detected manager.
     *
     * @return string
     */
    public function runPrefix(): string
    {
        return self::MANAGERS[$this->name()]['run'] ?? 'npm run';
    }

    /**
     * Detect the package manager from lock files, falling back to npm.
     *
     * @return string
     */
    protected function detect(): string
    {
        $root = $this->rootPath ?? (defined('ROOT') ? (string)constant('ROOT') : (string)getcwd());

        foreach (self::MANAGERS as $name => $manager) {
            if (is_file($root . DIRECTORY_SEPARATOR . $manager['lock'])) {
                return $name;
            }
        }

        return 'npm';
    }
}
