<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Command\LogsTuiCommand;
use Crustum\DevConsole\Service\NodePackageManager;

/**
 * Tests for the logs TUI launcher.
 */
class LogsTuiCommandTest extends TestCase
{
    /**
     * Exposed command builders for tests.
     *
     * @var object{tuiForTesting(\Crustum\DevConsole\Service\NodePackageManager, int, array<int,string>): string, serveForTesting(int, array<int,string>): string, runnerForTesting(\Crustum\DevConsole\Service\NodePackageManager): string, cliInForTesting(string): string|null}
     */
    protected object $command;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new class extends LogsTuiCommand {
            /**
             * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
             * @param int $tail Backfill lines.
             * @param list<string> $sources Sources.
             */
            public function tuiForTesting(NodePackageManager $packageManager, int $tail, array $sources): string
            {
                return $this->buildTuiCommand($packageManager, $tail, $sources);
            }

            /**
             * @param int $tail Backfill lines.
             * @param list<string> $sources Sources.
             */
            public function serveForTesting(int $tail, array $sources): string
            {
                return $this->buildServeCommand($tail, $sources);
            }

            /**
             * @param \Crustum\DevConsole\Service\NodePackageManager $packageManager Package manager.
             */
            public function runnerForTesting(NodePackageManager $packageManager): string
            {
                return $this->resolveLogsTuiRunner($packageManager);
            }

            /**
             * @param string $path Directory or file path.
             */
            public function cliInForTesting(string $path): ?string
            {
                return $this->logsTuiCliIn($path);
            }
        };
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        Configure::delete('DevConsole.logsTuiPath');

        parent::tearDown();
    }

    /**
     * Build arguments with the command's option defaults.
     *
     * @param array<string, mixed> $options Option overrides.
     * @return \Cake\Console\Arguments
     */
    protected function arguments(array $options = []): Arguments
    {
        return new Arguments([], array_merge([
            'tail' => '200',
            'sources' => null,
            'dry-run' => false,
        ], $options), []);
    }

    /**
     * Without an override the published npm package is used via the package manager.
     *
     * @return void
     */
    public function testDefaultRunnerUsesNpmPackage(): void
    {
        $runner = $this->command->runnerForTesting(new NodePackageManager(sys_get_temp_dir()));

        $this->assertStringContainsString('@crustum/log-tui', $runner);
    }

    /**
     * A checkout directory with dist/cli.js runs directly with node (dev override).
     *
     * @return void
     */
    public function testCheckoutDirOverride(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_logtui_' . uniqid();
        mkdir($dir . DIRECTORY_SEPARATOR . 'dist', 0775, true);
        $cli = $dir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'cli.js';
        file_put_contents($cli, '// stub');

        try {
            $this->assertSame($cli, $this->command->cliInForTesting($dir));
            $this->assertSame($cli, $this->command->cliInForTesting($cli));
            $this->assertNull($this->command->cliInForTesting(sys_get_temp_dir()));

            Configure::write('DevConsole.logsTuiPath', $dir);
            $runner = $this->command->runnerForTesting(new NodePackageManager(sys_get_temp_dir()));
            $this->assertStringContainsString('cli.js', $runner);
            $this->assertStringNotContainsString('@crustum/log-tui', $runner);

            // A directory without dist/cli.js falls back to the npm package.
            Configure::write('DevConsole.logsTuiPath', sys_get_temp_dir());
            $this->assertStringContainsString(
                '@crustum/log-tui',
                $this->command->runnerForTesting(new NodePackageManager(sys_get_temp_dir())),
            );
        } finally {
            unlink($cli);
            rmdir($dir . DIRECTORY_SEPARATOR . 'dist');
            rmdir($dir);
        }
    }

    /**
     * The TUI command carries tail/sources; the serve command targets this app's cake script.
     *
     * @return void
     */
    public function testBuiltCommands(): void
    {
        $manager = new NodePackageManager(sys_get_temp_dir());

        $tui = $this->command->tuiForTesting($manager, 50, ['cake_live']);
        $this->assertStringContainsString('--tail=50', $tui);
        $this->assertStringContainsString('cake_live', $tui);

        $serve = $this->command->serveForTesting(50, ['cake_live']);
        $this->assertStringContainsString('logs serve', $serve);
        $this->assertStringContainsString('--tail=50', $serve);
    }

    /**
     * Dry-run prints the command; invalid --tail fails.
     *
     * @return void
     */
    public function testDryRunAndInvalidTail(): void
    {
        $out = new StubConsoleOutput();
        $code = (new LogsTuiCommand())->execute(
            $this->arguments(['dry-run' => true]),
            new ConsoleIo($out, $out),
        );

        $this->assertSame(LogsTuiCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('@crustum/log-tui', implode("\n", $out->messages()));

        $code = (new LogsTuiCommand())->execute(
            $this->arguments(['tail' => 'bogus']),
            new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput()),
        );

        $this->assertSame(LogsTuiCommand::CODE_ERROR, $code);
    }
}
