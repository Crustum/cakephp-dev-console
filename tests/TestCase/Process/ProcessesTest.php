<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Process;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Process\Process;
use Crustum\DevConsole\Process\ProcessColor;
use Crustum\DevConsole\Process\Processes;
use Crustum\DevConsole\Process\ProcessMode;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for the Processes registry.
 */
class ProcessesTest extends TestCase
{
    /**
     * @var string|null
     */
    protected ?string $tmpRoot = null;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        Processes::reset();

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_' . uniqid();
        mkdir($root, 0777, true);

        $this->tmpRoot = $root;

        Processes::setRootPath($root);
        Configure::write('DevConsole.forceRegister', true);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        Processes::reset();
        Processes::setRootPath(null);
        Configure::delete('DevConsole.forceRegister');

        if ($this->tmpRoot !== null && is_dir($this->tmpRoot)) {
            $this->removeDir($this->tmpRoot);
            $this->tmpRoot = null;
        }

        parent::tearDown();
    }

    /**
     * Registered processes get palette colors in order.
     *
     * @return void
     */
    public function testRegisterAssignsColors(): void
    {
        Processes::register('php bin/cake.php server', 'server');
        Processes::register('npm run dev', 'vite');

        $commands = Processes::commands();

        $this->assertCount(2, $commands);
        $this->assertSame('server', $commands[0]['name']);
        $this->assertSame('#93c5fd', $commands[0]['color']);
        $this->assertSame('#c4b5fd', $commands[1]['color']);
    }

    /**
     * Higher priority registrations override lower ones by name.
     *
     * @return void
     */
    public function testHigherPriorityWins(): void
    {
        Processes::register('first', 'server');
        Processes::register('second', 'server');

        // Both register from this userland file with equal priority, so the
        // later registration wins.
        $commands = Processes::commands();

        $this->assertCount(1, $commands);
        $this->assertSame('second', $commands[0]['command']);
    }

    /**
     * only/except filter the process list.
     *
     * @return void
     */
    public function testOnlyAndExcept(): void
    {
        Processes::register('php bin/cake.php server', 'server');
        Processes::register('npm run dev', 'vite');

        Processes::only('server');

        $this->assertSame(['server'], array_column(Processes::commands(), 'name'));

        Processes::only('server', 'vite');
        Processes::except('vite');

        $this->assertSame(['server'], array_column(Processes::commands(), 'name'));
    }

    /**
     * Explicit order wins over registration order.
     *
     * @return void
     */
    public function testOrder(): void
    {
        Processes::register('php bin/cake.php server', 'server');
        Processes::register('npm run dev', 'vite');

        Processes::order(['vite', 'server']);

        $this->assertSame(['vite', 'server'], array_column(Processes::commands(), 'name'));
    }

    /**
     * cake/node helpers prefix commands correctly.
     *
     * @return void
     */
    public function testCakeAndNodeHelpers(): void
    {
        Processes::cake('server', 'server');
        Processes::node('dev', 'vite');

        $commands = Processes::commands();

        $this->assertSame('php bin/cake.php server', $commands[0]['command']);
        // No lock file in the temp root, so npm is the fallback manager.
        $this->assertSame('npm run dev', $commands[1]['command']);
    }

    /**
     * registerDefaults registers the server and vite when applicable.
     *
     * @return void
     */
    public function testRegisterDefaults(): void
    {
        file_put_contents($this->tmpRoot . DIRECTORY_SEPARATOR . 'package.json', '{}');

        Processes::registerDefaults();

        $names = array_column(Processes::commands(), 'name');

        $this->assertSame(['server', 'vite'], $names);
    }

    /**
     * Mode, timestamps, restart and buffer configuration round-trip.
     *
     * @return void
     */
    public function testConfiguration(): void
    {
        $this->assertSame(ProcessMode::TABS, Processes::mode());

        Processes::stream();

        $this->assertSame(ProcessMode::STREAM, Processes::mode());

        Processes::inline();

        $this->assertSame(ProcessMode::INLINE, Processes::mode());

        $this->assertFalse(Processes::shouldIncludeTimestamps());
        Processes::withTimestamps();
        $this->assertTrue(Processes::shouldIncludeTimestamps());

        $this->assertTrue(Processes::shouldAutoRestart());
        Processes::disableAutoRestart();
        $this->assertFalse(Processes::shouldAutoRestart());

        Processes::bufferSize(500);
        Processes::streamBufferSize(1000);

        $this->assertSame(500, Processes::getBufferSize());
        $this->assertSame(1000, Processes::getStreamBufferSize());
    }

    /**
     * Mode falls back to config unless set explicitly via the static API.
     *
     * @return void
     */
    public function testModeFromConfig(): void
    {
        $this->assertSame(ProcessMode::TABS, Processes::mode());

        Configure::write('DevConsole.mode', 'stream');

        try {
            $this->assertSame(ProcessMode::STREAM, Processes::mode());

            Processes::tabs();

            $this->assertSame(ProcessMode::TABS, Processes::mode());
        } finally {
            Configure::delete('DevConsole.mode');
        }

        Processes::reset();

        Configure::write('DevConsole.mode', 'bogus');

        try {
            $this->assertSame(ProcessMode::TABS, Processes::mode());
        } finally {
            Configure::delete('DevConsole.mode');
        }
    }

    /**
     * withoutDefaultCommands hides registerDefaults() processes.
     *
     * @return void
     */
    public function testWithoutDefaultCommands(): void
    {
        Processes::registerDefaults();
        Processes::register('custom', 'custom');

        $this->assertCount(2, Processes::commands());

        Processes::withoutDefaultCommands();

        $this->assertSame(['custom'], array_column(Processes::commands(), 'name'));
    }

    /**
     * registerMany registers definitions with userland priority and colors.
     *
     * @return void
     */
    public function testRegisterMany(): void
    {
        Processes::registerDefaults();

        Processes::registerMany([
            ['command' => 'custom server', 'name' => 'server', 'color' => '#86efac'],
            ['command' => 'npm run watch', 'name' => 'assets'],
            ['command' => ''],
            ['name' => 'missing-command'],
            'not-an-array',
        ]);

        $commands = Processes::commands();
        $byName = array_column($commands, null, 'name');

        // Config entry overrides the default `server`.
        $this->assertSame('custom server', $byName['server']['command']);
        $this->assertSame('#86efac', $byName['server']['color']);
        $this->assertSame(Process::PRIORITY_USERLAND, $byName['server']['priority']);

        $this->assertSame('npm run watch', $byName['assets']['command']);

        // Malformed entries are skipped: server + assets only.
        $this->assertCount(2, $commands);
    }

    /**
     * Explicit priority wins over backtrace resolution.
     *
     * @return void
     */
    public function testExplicitPriority(): void
    {
        Processes::register('default-ish', 'server', Process::PRIORITY_DEFAULT);
        Processes::register('vendor-ish', 'server', Process::PRIORITY_VENDOR);

        $commands = Processes::commands();

        $this->assertSame('vendor-ish', $commands[0]['command']);
    }

    /**
     * Pid file lifecycle: missing, stale and live pids.
     *
     * @return void
     */
    public function testPidFileLifecycle(): void
    {
        $file = Processes::pidFile();

        $this->assertStringEndsWith('.pid', $file);
        $this->assertNull(Processes::runningPid());

        file_put_contents($file, '2147483647');

        try {
            $this->assertNull(Processes::runningPid());
            $this->assertFalse(is_file($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->assertTrue(Processes::isPidRunning(getmypid()));
        $this->assertFalse(Processes::isPidRunning(-1));

        Processes::killTree(-1);
        $this->assertTrue(true);
    }

    /**
     * A pid file pointing at a live process reports it as running.
     *
     * @return void
     */
    public function testLivePidReported(): void
    {
        $file = Processes::pidFile();

        $this->assertNotEmpty(Processes::pidCommandLine(getmypid()));

        file_put_contents($file, (string)getmypid());

        try {
            $this->assertSame(getmypid(), Processes::runningPid());
            $this->assertTrue(is_file($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Zombies count as stopped: a dead-but-unreaped pid is not running.
     *
     * Guards the Linux CI failure where `dev stop` killed a supervised
     * child that stayed a zombie until its proc handle was closed, and
     * `posix_kill($pid, 0)` kept reporting it alive.
     *
     * @return void
     */
    public function testZombiePidIsNotRunning(): void
    {
        $method = new ReflectionMethod(Processes::class, 'isZombie');

        $this->assertFalse($method->invoke(null, getmypid()));
        $this->assertFalse($method->invoke(null, 2147483647));
        $this->assertFalse(Processes::isPidRunning(2147483647));
    }

    /**
     * Register returns the process instance and derives missing names.
     *
     * @return void
     */
    public function testRegisterReturnsInstanceAndDerivesName(): void
    {
        $process = Processes::register('echo hello', 'greeter');

        $this->assertInstanceOf(Process::class, $process);

        Processes::register('echo hello world');

        $byName = array_column(Processes::commands(), null, 'name');

        $this->assertSame('echo hello', $byName['greeter']['command']);
        $this->assertSame('echo hello world', $byName['echo']['command']);
    }

    /**
     * Plain registrations resolve with userland priority.
     *
     * @return void
     */
    public function testUserlandRegistrationGetsUserlandPriority(): void
    {
        Processes::register('echo hello', 'greeter');

        $commands = Processes::commands();

        $this->assertSame(Process::PRIORITY_USERLAND, $commands[0]['priority']);
    }

    /**
     * Ordering keeps processes without an explicit position in registration order.
     *
     * @return void
     */
    public function testOrderLeavesUnorderedInRegistrationOrder(): void
    {
        Processes::register('echo one', 'one');
        Processes::register('echo two', 'two');
        Processes::register('echo three', 'three');
        Processes::register('echo four', 'four');

        Processes::order(['four']);

        $this->assertSame(
            ['four', 'one', 'two', 'three'],
            array_column(Processes::commands(), 'name'),
        );
    }

    /**
     * Explicit colors are preserved and skipped by auto-assignment.
     *
     * @return void
     */
    public function testExplicitColorIsPreserved(): void
    {
        Processes::register('echo one', 'one')->pink();
        Processes::register('echo two', 'two');

        $commands = Processes::commands();

        $this->assertSame(ProcessColor::PINK->value, $commands[0]['color']);
        $this->assertNotSame(ProcessColor::PINK->value, $commands[1]['color']);
    }

    /**
     * Palette colors recycle once every color is in use.
     *
     * @return void
     */
    public function testColorsRecycleWhenAllUsed(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            Processes::register("cmd{$i}", "c{$i}");
        }

        $commands = Processes::commands();

        $this->assertCount(7, $commands);

        foreach ($commands as $command) {
            $this->assertNotEmpty($command['color']);
        }
    }

    /**
     * Registered processes track the external caller as source.
     *
     * @return void
     */
    public function testRegisteredCommandIncludesSource(): void
    {
        Processes::register('echo hello', 'greeter');

        $commands = Processes::commands();

        $this->assertArrayHasKey('source', $commands[0]);
        $this->assertIsArray($commands[0]['source']);
        $this->assertSame(static::class, $commands[0]['source']['class']);
    }

    /**
     * Userland registrations overwrite vendor and default priorities.
     *
     * @return void
     */
    public function testUserlandOverwritesVendorAndDefault(): void
    {
        Processes::registerDefaults();
        Processes::register('vendor-ish', 'vendor', Process::PRIORITY_VENDOR);
        Processes::register('custom-server', 'server');
        Processes::register('custom-vendor', 'vendor');

        $byName = array_column(Processes::commands(), null, 'name');

        $this->assertSame('custom-server', $byName['server']['command']);
        $this->assertSame(Process::PRIORITY_USERLAND, $byName['server']['priority']);
        $this->assertSame('custom-vendor', $byName['vendor']['command']);
    }

    /**
     * Defaults never overwrite userland or vendor registrations.
     *
     * @return void
     */
    public function testDefaultDoesNotOverwriteUserlandOrVendor(): void
    {
        Processes::register('userland-server', 'server');
        Processes::register('vendor-ish', 'vendor', Process::PRIORITY_VENDOR);

        Processes::registerDefaults();

        $byName = array_column(Processes::commands(), null, 'name');

        $this->assertSame('userland-server', $byName['server']['command']);
        $this->assertSame('vendor-ish', $byName['vendor']['command']);
    }

    /**
     * Defaults register with the lowest priority.
     *
     * @return void
     */
    public function testDefaultPriorityIsLowest(): void
    {
        Processes::registerDefaults();

        $byName = array_column(Processes::commands(), null, 'name');

        $this->assertSame(Process::PRIORITY_DEFAULT, $byName['server']['priority']);
    }

    /**
     * Vendor processes are included by default and can be excluded.
     *
     * @return void
     */
    public function testVendorCommandsFiltering(): void
    {
        Processes::register('php bin/cake.php server', 'server', Process::PRIORITY_DEFAULT);
        Processes::register('echo vendor', 'vendor', Process::PRIORITY_VENDOR);
        Processes::register('echo local', 'local');

        $names = array_column(Processes::commands(), 'name');

        $this->assertContains('vendor', $names);

        Processes::withoutVendorCommands();

        $this->assertSame(['server', 'local'], array_column(Processes::commands(), 'name'));

        Processes::withoutDefaultCommands();

        $this->assertSame(['local'], array_column(Processes::commands(), 'name'));
    }

    /**
     * Priority resolves from synthetic backtraces: defaults, vendor, userland.
     *
     * @return void
     */
    public function testResolvePriorityFromTrace(): void
    {
        $ref = new ReflectionClass(Processes::class);
        $method = $ref->getMethod('resolvePriority');

        $selfFrame = [
            'file' => $ref->getFileName(),
            'line' => 99,
            'function' => 'register',
            'class' => Processes::class,
        ];

        $root = $this->tmpRoot . DIRECTORY_SEPARATOR;

        // Synthetic traces use native separators, like real backtraces.
        $join = fn(string ...$parts): string => implode(DIRECTORY_SEPARATOR, $parts);

        $this->assertSame(
            Process::PRIORITY_DEFAULT,
            $method->invoke(null, [
                $selfFrame,
                ['file' => $root . $join('config', 'bootstrap.php'), 'line' => 1, 'function' => 'registerDefaults', 'class' => Processes::class],
            ]),
        );

        $this->assertSame(
            Process::PRIORITY_VENDOR,
            $method->invoke(null, [
                $selfFrame,
                ['file' => $root . $join('vendor', 'some-package', 'src', 'ServiceProvider.php'), 'line' => 10, 'function' => 'register', 'class' => Processes::class],
            ]),
        );

        $this->assertSame(
            Process::PRIORITY_USERLAND,
            $method->invoke(null, [
                $selfFrame,
                ['file' => $root . $join('src', 'Application.php'), 'line' => 19, 'function' => 'cake', 'class' => Processes::class],
            ]),
        );

        // A vendor helper called from userland code resolves as userland.
        $this->assertSame(
            Process::PRIORITY_USERLAND,
            $method->invoke(null, [
                ['file' => $root . $join('vendor', 'some-package', 'src', 'Helper.php'), 'line' => 10, 'function' => 'register', 'class' => Processes::class],
                ['file' => $root . $join('src', 'Application.php'), 'line' => 25, 'function' => 'setupDev', 'class' => 'Some\\Package\\Helper'],
            ]),
        );
    }

    /**
     * Remove a directory recursively.
     *
     * @param string $dir Directory path.
     * @return void
     */
    protected function removeDir(string $dir): void
    {
        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
