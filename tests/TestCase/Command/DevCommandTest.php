<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Command\DevCommand;
use Crustum\DevConsole\Process\Processes;
use Crustum\DevConsole\Process\ProcessMode;
use Crustum\DevConsole\Service\NodePackageManager;
use ReflectionMethod;
use ReflectionProperty;
use SignalHandler\Signal\Signal;

/**
 * Tests for the multiplex/concurrently command builders.
 */
class DevCommandTest extends TestCase
{
    /**
     * Exposed command builder for tests.
     *
     * @var object{buildForTesting(): string, concurrentlyForTesting(): string, optionsForTesting(): array, runnerForTesting(): string|null}
     */
    protected object $command;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new class extends DevCommand {
            /**
             * @param array<int, array{name: string, command: string, source: array{file?: string, line?: int, class?: string, function?: string}, color: string, priority: int}> $devCommands
             * @param array{mode: \Crustum\DevConsole\Process\ProcessMode, timestamps: bool, noRestart: bool, json: bool, bufferSize: int|null, streamBufferSize: int|null} $options
             */
            public function buildForTesting(
                array $devCommands,
                NodePackageManager $packageManager,
                array $options,
            ): string {
                return $this->buildMultiplexCommand($devCommands, $packageManager, $options);
            }

            /**
             * @param array<int, array{name: string, command: string, source: array{file?: string, line?: int, class?: string, function?: string}, color: string, priority: int}> $devCommands
             * @param array{mode: \Crustum\DevConsole\Process\ProcessMode, timestamps: bool, noRestart: bool, json: bool, bufferSize: int|null, streamBufferSize: int|null} $options
             */
            public function concurrentlyForTesting(array $devCommands, array $options): string
            {
                return $this->buildConcurrentlyCommand($devCommands, $options);
            }

            /**
             * @param \Cake\Console\Arguments $args
             */
            public function runnerForTesting(Arguments $args): ?string
            {
                return $this->resolveRunner($args);
            }

            /**
             * @param \Cake\Console\Arguments $args
             * @return array{mode: \Crustum\DevConsole\Process\ProcessMode, timestamps: bool, noRestart: bool, json: bool, bufferSize: int|null, streamBufferSize: int|null}
             */
            public function optionsForTesting(Arguments $args): array
            {
                return $this->commandOptions($args);
            }
        };
    }

    /**
     * Multiplex args use the `name@color,command` format, shell-escaped.
     *
     * @return void
     */
    public function testBuildMultiplexCommand(): void
    {
        $devCommands = [
            [
                'name' => 'server',
                'command' => 'php bin/cake.php server',
                'source' => [],
                'color' => '#93c5fd',
                'priority' => 0,
            ],
            [
                'name' => 'vite',
                'command' => 'npm run dev',
                'source' => [],
                'color' => '#fcd34d',
                'priority' => 0,
            ],
        ];

        $command = $this->command->buildForTesting(
            $devCommands,
            new NodePackageManager(sys_get_temp_dir()),
            [
                'mode' => ProcessMode::TABS,
                'timestamps' => false,
                'noRestart' => false,
                'json' => false,
                'bufferSize' => null,
                'streamBufferSize' => null,
            ],
        );

        // Runner: explicit multiplexPath (node + cli.js) or @crustum/multiplex via npm.
        $this->assertTrue(
            str_contains($command, 'cli.js') || str_contains($command, '@crustum/multiplex'),
            "Unexpected runner in: {$command}",
        );
        $this->assertStringContainsString('--title', $command);
        $this->assertStringContainsString(
            escapeshellarg('server@#93c5fd,php bin/cake.php server'),
            $command,
        );
        $this->assertStringContainsString(
            escapeshellarg('vite@#fcd34d,npm run dev'),
            $command,
        );
        $this->assertStringNotContainsString('--stream', $command);
        $this->assertStringNotContainsString('--inline', $command);
    }

    /**
     * Mode and output flags map to multiplex flags.
     *
     * @return void
     */
    public function testBuildMultiplexCommandFlags(): void
    {
        $devCommands = [
            [
                'name' => 'server',
                'command' => 'php bin/cake.php server',
                'source' => [],
                'color' => '#93c5fd',
                'priority' => 0,
            ],
        ];

        $command = $this->command->buildForTesting(
            $devCommands,
            new NodePackageManager(sys_get_temp_dir()),
            [
                'mode' => ProcessMode::STREAM,
                'timestamps' => true,
                'noRestart' => true,
                'json' => false,
                'bufferSize' => 500,
                'streamBufferSize' => 1000,
            ],
        );

        $this->assertStringContainsString('--stream', $command);
        $this->assertStringContainsString('--timestamps', $command);
        $this->assertStringContainsString('--no-restart', $command);
        $this->assertStringContainsString('--buffer-size=' . escapeshellarg('500'), $command);
        $this->assertStringContainsString('--stream-buffer-size=' . escapeshellarg('1000'), $command);
    }

    /**
     * Inline mode maps to the multiplex inline flag.
     *
     * @return void
     */
    public function testBuildMultiplexCommandInline(): void
    {
        $command = $this->command->buildForTesting(
            [
                [
                    'name' => 'server',
                    'command' => 'php bin/cake.php server',
                    'source' => [],
                    'color' => '#93c5fd',
                    'priority' => 0,
                ],
            ],
            new NodePackageManager(sys_get_temp_dir()),
            [
                'mode' => ProcessMode::INLINE,
                'timestamps' => false,
                'noRestart' => false,
                'json' => true,
                'bufferSize' => null,
                'streamBufferSize' => null,
            ],
        );

        $this->assertStringContainsString('--inline', $command);
        $this->assertStringContainsString('--json', $command);
    }

    /**
     * Colons in labels survive multiplex arg building unescaped.
     *
     * @return void
     */
    public function testBuildMultiplexCommandKeepsColonsInLabels(): void
    {
        $command = $this->command->buildForTesting(
            [
                [
                    'name' => 'queue:work',
                    'command' => 'php bin/cake.php queue worker',
                    'source' => [],
                    'color' => '#c4b5fd',
                    'priority' => 0,
                ],
            ],
            new NodePackageManager(sys_get_temp_dir()),
            [
                'mode' => ProcessMode::TABS,
                'timestamps' => false,
                'noRestart' => false,
                'json' => false,
                'bufferSize' => null,
                'streamBufferSize' => null,
            ],
        );

        $this->assertStringContainsString(
            escapeshellarg('queue:work@#c4b5fd,php bin/cake.php queue worker'),
            $command,
        );
    }

    /**
     * The multiplex title carries the application folder name.
     *
     * @return void
     */
    public function testBuildMultiplexCommandUsesAppFolderInTitle(): void
    {
        Processes::reset();
        Processes::setRootPath(sys_get_temp_dir());

        try {
            $command = $this->command->buildForTesting(
                [
                    [
                        'name' => 'server',
                        'command' => 'php bin/cake.php server',
                        'source' => [],
                        'color' => '#93c5fd',
                        'priority' => 0,
                    ],
                ],
                new NodePackageManager(sys_get_temp_dir()),
                [
                    'mode' => ProcessMode::TABS,
                    'timestamps' => false,
                    'noRestart' => false,
                    'json' => false,
                    'bufferSize' => null,
                    'streamBufferSize' => null,
                ],
            );

            $this->assertStringContainsString(
                '--title ' . escapeshellarg('cake dev · ' . basename(sys_get_temp_dir())),
                $command,
            );
        } finally {
            Processes::reset();
            Processes::setRootPath(null);
        }
    }

    /**
     * The configured app name wins over the folder name in the title.
     *
     * @return void
     */
    public function testBuildMultiplexCommandUsesAppNameInTitleWhenSet(): void
    {
        Configure::write('DevConsole.appName', 'Acme');

        try {
            $command = $this->command->buildForTesting(
                [
                    [
                        'name' => 'server',
                        'command' => 'php bin/cake.php server',
                        'source' => [],
                        'color' => '#93c5fd',
                        'priority' => 0,
                    ],
                ],
                new NodePackageManager(sys_get_temp_dir()),
                [
                    'mode' => ProcessMode::TABS,
                    'timestamps' => false,
                    'noRestart' => false,
                    'json' => false,
                    'bufferSize' => null,
                    'streamBufferSize' => null,
                ],
            );

            $this->assertStringContainsString(
                '--title ' . escapeshellarg('cake dev · Acme'),
                $command,
            );
        } finally {
            Configure::delete('DevConsole.appName');
        }
    }

    /**
     * The shipped config defaults appName from the APP_NAME env var.
     *
     * Host apps receive this file via manifest and override the literal
     * when they want a pinned title.
     *
     * @return void
     */
    public function testShippedConfigDefaultsAppNameFromEnv(): void
    {
        $previousEnv = getenv('APP_NAME');
        $hadServer = array_key_exists('APP_NAME', $_SERVER);
        $previousServer = $_SERVER['APP_NAME'] ?? null;

        putenv('APP_NAME=EnvApp');
        $_SERVER['APP_NAME'] = 'EnvApp';

        try {
            /** @var array{DevConsole: array{appName: mixed}} $config */
            $config = require Plugin::configPath('Crustum/DevConsole') . 'dev_console.php';

            $this->assertSame('EnvApp', $config['DevConsole']['appName']);
        } finally {
            if ($previousEnv === false) {
                putenv('APP_NAME');
            } else {
                putenv('APP_NAME=' . $previousEnv);
            }

            if ($hadServer) {
                $_SERVER['APP_NAME'] = $previousServer;
            } else {
                unset($_SERVER['APP_NAME']);
            }
        }
    }

    /**
     * CLI mode flags win over the configured mode, which wins over tabs.
     *
     * @return void
     */
    public function testCommandOptionsModeFlagOverridesConfiguredMode(): void
    {
        Processes::reset();

        try {
            $options = $this->command->optionsForTesting(new Arguments([], [], []));

            $this->assertSame(ProcessMode::TABS, $options['mode']);

            Processes::stream();

            $options = $this->command->optionsForTesting(new Arguments([], [], []));

            $this->assertSame(ProcessMode::STREAM, $options['mode']);

            $options = $this->command->optionsForTesting(new Arguments([], ['tabs' => true], []));

            $this->assertSame(ProcessMode::TABS, $options['mode']);
        } finally {
            Processes::reset();
        }
    }

    /**
     * Timestamps and restart config flow into the resolved options.
     *
     * @return void
     */
    public function testCommandOptionsTimestampsAndRestartFromConfiguration(): void
    {
        Processes::reset();

        try {
            $options = $this->command->optionsForTesting(new Arguments([], [], []));

            $this->assertFalse($options['timestamps']);
            $this->assertFalse($options['noRestart']);

            Processes::withTimestamps();
            Processes::disableAutoRestart();

            $options = $this->command->optionsForTesting(new Arguments([], [], []));

            $this->assertTrue($options['timestamps']);
            $this->assertTrue($options['noRestart']);

            $options = $this->command->optionsForTesting(new Arguments([], ['timestamps' => true], []));

            $this->assertTrue($options['timestamps']);
        } finally {
            Processes::reset();
        }
    }

    /**
     * Buffer sizes come from CLI flags, falling back to configuration.
     *
     * @return void
     */
    public function testCommandOptionsBufferSizes(): void
    {
        Processes::reset();

        try {
            $options = $this->command->optionsForTesting(new Arguments([], [], []));

            $this->assertNull($options['bufferSize']);
            $this->assertNull($options['streamBufferSize']);

            Processes::bufferSize(1000);
            Processes::streamBufferSize(2000);

            $options = $this->command->optionsForTesting(new Arguments([], [], []));

            $this->assertSame(1000, $options['bufferSize']);
            $this->assertSame(2000, $options['streamBufferSize']);

            $options = $this->command->optionsForTesting(
                new Arguments([], ['buffer-size' => '50', 'stream-buffer-size' => '60'], []),
            );

            $this->assertSame(50, $options['bufferSize']);
            $this->assertSame(60, $options['streamBufferSize']);
        } finally {
            Processes::reset();
        }
    }

    /**
     * Concurrently command carries names, colors and restart flags.
     *
     * @return void
     */
    public function testBuildConcurrentlyCommand(): void
    {
        $devCommands = [
            [
                'name' => 'server',
                'command' => 'php bin/cake.php server',
                'source' => [],
                'color' => '#93c5fd',
                'priority' => 0,
            ],
            [
                'name' => 'vite',
                'command' => 'npm run dev',
                'source' => [],
                'color' => '#fcd34d',
                'priority' => 0,
            ],
        ];

        $options = [
            'mode' => ProcessMode::TABS,
            'timestamps' => true,
            'noRestart' => false,
            'json' => false,
            'bufferSize' => null,
            'streamBufferSize' => null,
        ];

        $command = $this->command->concurrentlyForTesting($devCommands, $options);

        $this->assertStringContainsString('concurrently -c "#93c5fd,#fcd34d"', $command);
        $this->assertStringContainsString('--names=server,vite', $command);
        $this->assertStringContainsString('--restart-tries=5 --restart-after=1000', $command);
        $this->assertStringContainsString('--timestamp-format="HH:mm:ss" -p "{time} [{name}]"', $command);
    }

    /**
     * Runner resolution: CLI flag wins, then config, then OS default.
     *
     * @return void
     */
    public function testResolveRunner(): void
    {
        // No flag, no config: OS default.
        $expected = PHP_OS_FAMILY === 'Windows' ? 'concurrently' : 'multiplex';
        $args = new Arguments([], ['runner' => null], []);
        $this->assertSame($expected, $this->command->runnerForTesting($args));

        // CLI flag wins over everything.
        $args = new Arguments([], ['runner' => 'multiplex'], []);
        $this->assertSame('multiplex', $this->command->runnerForTesting($args));

        // Invalid runner is rejected.
        $args = new Arguments([], ['runner' => 'tmux'], []);
        $this->assertNull($this->command->runnerForTesting($args));

        // Config value is honored when no flag is given.
        Configure::write('DevConsole.runner', 'concurrently');
        try {
            $args = new Arguments([], ['runner' => null], []);
            $this->assertSame('concurrently', $this->command->runnerForTesting($args));

            $args = new Arguments([], ['runner' => 'multiplex'], []);
            $this->assertSame('multiplex', $this->command->runnerForTesting($args));
        } finally {
            Configure::delete('DevConsole.runner');
        }
    }

    /**
     * Dry run prints the command instead of executing it.
     *
     * @return void
     */
    public function testDryRun(): void
    {
        Processes::reset();

        try {
            Processes::register('php bin/cake.php server', 'server');

            $out = new StubConsoleOutput();
            $io = new ConsoleIo($out, $out);
            $command = new DevCommand();

            $code = $command->execute(
                new Arguments([], ['dry-run' => true, 'runner' => 'multiplex'], []),
                $io,
            );

            $this->assertSame(DevCommand::CODE_SUCCESS, $code);
            $this->assertStringContainsString('server@', implode("\n", $out->messages()));
        } finally {
            Processes::reset();
        }
    }

    /**
     * A repeated console interrupt force-kills the supervised tree.
     *
     * The first interrupt is graceful (nothing killed); the second one kills.
     * A zero child pid is a safe no-op, so this runs on any OS.
     *
     * @return void
     */
    public function testConsoleInterrupt(): void
    {
        $command = new DevCommand();
        $method = new ReflectionMethod(DevCommand::class, 'onConsoleInterrupt');
        $property = new ReflectionProperty(DevCommand::class, 'interruptCount');

        $method->invoke($command);
        $this->assertSame(1, $property->getValue($command));

        $method->invoke($command);
        $this->assertSame(2, $property->getValue($command));
    }

    /**
     * Signal handlers bind and unbind without errors.
     *
     * @return void
     */
    public function testSignalBindUnbind(): void
    {
        $command = new DevCommand();

        $command->bindSignals([Signal::SIGINT], function (): void {
        });
        $command->unbindSignals();

        $this->assertTrue(true);
    }

    /**
     * A live pid file blocks a duplicate run.
     *
     * @return void
     */
    public function testDuplicateRunBlocked(): void
    {
        Processes::reset();

        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }

        try {
            Processes::register('php bin/cake.php server', 'server');
            file_put_contents($file, (string)getmypid());

            $out = new StubConsoleOutput();
            $io = new ConsoleIo($out, $out);

            $code = (new DevCommand())->execute(
                new Arguments([], ['dry-run' => false, 'runner' => 'multiplex'], []),
                $io,
            );

            $this->assertSame(DevCommand::CODE_ERROR, $code);
            $this->assertStringContainsString((string)getmypid(), implode("\n", $out->messages()));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }

            Processes::reset();
        }
    }

    /**
     * A stale pid file is removed and the run proceeds.
     *
     * @return void
     */
    public function testStalePidFileRemoved(): void
    {
        Processes::reset();

        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }

        try {
            Processes::register('php bin/cake.php server', 'server');
            file_put_contents($file, '2147483647');

            $out = new StubConsoleOutput();
            $io = new ConsoleIo($out, $out);

            $code = (new DevCommand())->execute(
                new Arguments([], ['dry-run' => true, 'runner' => 'multiplex'], []),
                $io,
            );

            $this->assertSame(DevCommand::CODE_SUCCESS, $code);
            $this->assertFalse(is_file($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }

            Processes::reset();
        }
    }

    /**
     * Child lookup returns null when there is no such child.
     *
     * @return void
     */
    public function testFindChildPid(): void
    {
        $command = new class extends DevCommand {
            /**
             * @param int $parentPid Parent process id.
             * @param string $name Executable name.
             */
            public function findForTesting(int $parentPid, string $name): ?int
            {
                return $this->findChildPid($parentPid, $name);
            }
        };

        $this->assertNull($command->findForTesting(getmypid(), 'definitely-not-a-process.exe'));
        $this->assertNull($command->findForTesting(-1, 'node.exe'));
    }

    /**
     * Disabled restart maps to kill-others-on-fail.
     *
     * @return void
     */
    public function testBuildConcurrentlyCommandNoRestart(): void
    {
        $command = $this->command->concurrentlyForTesting(
            [
                [
                    'name' => 'server',
                    'command' => 'php bin/cake.php server',
                    'source' => [],
                    'color' => '#93c5fd',
                    'priority' => 0,
                ],
            ],
            [
                'mode' => ProcessMode::TABS,
                'timestamps' => false,
                'noRestart' => true,
                'json' => false,
                'bufferSize' => null,
                'streamBufferSize' => null,
            ],
        );

        $this->assertStringContainsString('--kill-others-on-fail', $command);
        $this->assertStringNotContainsString('--restart-tries', $command);
    }
}
