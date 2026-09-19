<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Command\DevListCommand;
use Crustum\DevConsole\Process\Process;
use Crustum\DevConsole\Process\Processes;
use ReflectionMethod;

/**
 * Tests for the dev_list command.
 */
class DevListCommandTest extends TestCase
{
    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        Processes::reset();
        Processes::setRootPath(sys_get_temp_dir());
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        Processes::reset();
        Processes::setRootPath(null);

        parent::tearDown();
    }

    /**
     * Empty registry prints a notice and succeeds without filters.
     *
     * @return void
     */
    public function testEmptyRegistry(): void
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $command = new DevListCommand();

        $code = $command->execute(
            new Arguments([], ['json' => false, 'filter' => null, 'except-vendor' => false, 'only-vendor' => false], []),
            $io,
        );

        $this->assertSame(DevListCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString("doesn't have any dev processes", implode("\n", $out->messages()));
    }

    /**
     * Filtering down to nothing is an error.
     *
     * @return void
     */
    public function testFilteringToEmptyFails(): void
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $command = new DevListCommand();

        $code = $command->execute(
            new Arguments([], ['json' => false, 'filter' => 'nope', 'except-vendor' => false, 'only-vendor' => false], []),
            $io,
        );

        $this->assertSame(DevListCommand::CODE_ERROR, $code);
    }

    /**
     * JSON output lists processes with formatted sources.
     *
     * @return void
     */
    public function testJsonOutput(): void
    {
        Processes::register('php bin/cake.php server', 'server');

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $command = new DevListCommand();

        $code = $command->execute(
            new Arguments([], ['json' => true, 'filter' => null, 'except-vendor' => false, 'only-vendor' => false], []),
            $io,
        );

        $this->assertSame(DevListCommand::CODE_SUCCESS, $code);

        $decoded = json_decode(implode("\n", $out->messages()), true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('server', $decoded[0]['name']);
        $this->assertSame(Process::PRIORITY_USERLAND, $decoded[0]['priority']);
    }

    /**
     * Human output lists each process and a count line.
     *
     * @return void
     */
    public function testHumanOutput(): void
    {
        Processes::register('php bin/cake.php server', 'server');
        Processes::register('npm run dev', 'vite');

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $command = new DevListCommand();

        $code = $command->execute(
            new Arguments([], ['json' => false, 'filter' => null, 'except-vendor' => false, 'only-vendor' => false], []),
            $io,
        );

        $this->assertSame(DevListCommand::CODE_SUCCESS, $code);

        $messages = implode("\n", $out->messages());

        $this->assertStringContainsString('server', $messages);
        $this->assertStringContainsString('vite', $messages);
        $this->assertStringContainsString('Showing [2] dev processes', $messages);
    }

    /**
     * A single process uses the singular count label.
     *
     * @return void
     */
    public function testSingularProcessLabel(): void
    {
        Processes::register('php bin/cake.php server', 'server');

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $command = new DevListCommand();

        $code = $command->execute(
            new Arguments([], ['json' => false, 'filter' => null, 'except-vendor' => false, 'only-vendor' => false], []),
            $io,
        );

        $this->assertSame(DevListCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('Showing [1] dev process', implode("\n", $out->messages()));
    }

    /**
     * Filter matches against process names.
     *
     * @return void
     */
    public function testFilterByName(): void
    {
        Processes::register('echo hello', 'greeter');
        Processes::register('php bin/cake.php server', 'server');

        $decoded = $this->getJsonOutput(['filter' => 'server']);

        $this->assertCount(1, $decoded);
        $this->assertSame('server', $decoded[0]['name']);
    }

    /**
     * Filter matches against process commands.
     *
     * @return void
     */
    public function testFilterByCommand(): void
    {
        Processes::register('echo hello', 'greeter');
        Processes::register('php bin/cake.php server', 'server');

        $decoded = $this->getJsonOutput(['filter' => 'bin/cake']);

        $this->assertCount(1, $decoded);
        $this->assertSame('server', $decoded[0]['name']);
    }

    /**
     * A filter matching several processes lists each of them.
     *
     * @return void
     */
    public function testFilterMatchesMultiple(): void
    {
        Processes::register('echo hello', 'greeter');
        Processes::register('php bin/cake.php server', 'server');
        Processes::register('php bin/cake.php queue worker', 'queue');

        $decoded = $this->getJsonOutput(['filter' => 'bin/cake']);

        $this->assertCount(2, $decoded);
        $this->assertSame('server', $decoded[0]['name']);
        $this->assertSame('queue', $decoded[1]['name']);
    }

    /**
     * JSON output carries every field with the source formatted for display.
     *
     * @return void
     */
    public function testJsonOutputContainsAllFields(): void
    {
        Processes::register('php bin/cake.php server', 'server');

        $decoded = $this->getJsonOutput();

        $this->assertCount(1, $decoded);
        $this->assertSame(
            ['command', 'name', 'color', 'source', 'priority'],
            array_keys($decoded[0]),
        );
        $this->assertSame('php bin/cake.php server', $decoded[0]['command']);
        $this->assertNotEmpty($decoded[0]['color']);
        $this->assertStringContainsString(static::class, $decoded[0]['source']);
    }

    /**
     * The vendor filter hides vendor-registered processes.
     *
     * @return void
     */
    public function testExceptVendorExcludesVendorProcesses(): void
    {
        Processes::register('echo hello', 'app-cmd');
        Processes::register('echo vendor', 'vendor-cmd', Process::PRIORITY_VENDOR);

        $decoded = $this->getJsonOutput(['except-vendor' => true]);

        $this->assertCount(1, $decoded);
        $this->assertSame('app-cmd', $decoded[0]['name']);
    }

    /**
     * Requesting only vendor processes fails when there are none.
     *
     * @return void
     */
    public function testOnlyVendorWithoutVendorProcessesFails(): void
    {
        Processes::register('echo hello', 'app-cmd');

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $command = new DevListCommand();

        $code = $command->execute(
            new Arguments([], ['json' => true, 'filter' => null, 'except-vendor' => false, 'only-vendor' => true], []),
            $io,
        );

        $this->assertSame(DevListCommand::CODE_ERROR, $code);
        $this->assertSame('[]', implode("\n", $out->messages()));
    }

    /**
     * Name filtering and the vendor filter combine.
     *
     * @return void
     */
    public function testCombinedFilterAndVendorOptions(): void
    {
        Processes::register('php bin/cake.php server', 'server');
        Processes::register('php vendor/bin/server-tool', 'srvtool', Process::PRIORITY_VENDOR);

        $decoded = $this->getJsonOutput(['filter' => 'server', 'except-vendor' => true]);

        $this->assertCount(1, $decoded);
        $this->assertSame('server', $decoded[0]['name']);
    }

    /**
     * Class sources format as `Class@method`.
     *
     * @return void
     */
    public function testFormatSourceWithClassAndFunction(): void
    {
        $method = new ReflectionMethod(DevListCommand::class, 'formatSource');

        $result = $method->invoke(new DevListCommand(), [
            'file' => '/some/path.php',
            'line' => 42,
            'class' => 'App\\Application',
            'function' => 'bootstrap',
        ]);

        $this->assertSame('App\\Application@bootstrap', $result);
    }

    /**
     * File sources outside the app root stay absolute with their line.
     *
     * @return void
     */
    public function testFormatSourceWithFileAndLine(): void
    {
        $method = new ReflectionMethod(DevListCommand::class, 'formatSource');

        $result = $method->invoke(new DevListCommand(), [
            'file' => '/app/config/bootstrap.php',
            'line' => 15,
        ]);

        $this->assertSame('/app/config/bootstrap.php:15', $result);
    }

    /**
     * An empty source formats as an empty string.
     *
     * @return void
     */
    public function testFormatSourceWithEmptyArray(): void
    {
        $method = new ReflectionMethod(DevListCommand::class, 'formatSource');

        $this->assertSame('', $method->invoke(new DevListCommand(), []));
    }

    /**
     * Run the list command with JSON output and return the decoded rows.
     *
     * @param array<string, mixed> $options Option overrides.
     * @return array<int, array<string, mixed>>
     */
    protected function getJsonOutput(array $options = []): array
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $command = new DevListCommand();

        $code = $command->execute(
            new Arguments([], array_merge([
                'json' => true,
                'filter' => null,
                'except-vendor' => false,
                'only-vendor' => false,
            ], $options), []),
            $io,
        );

        $this->assertSame(DevListCommand::CODE_SUCCESS, $code);

        $decoded = json_decode(implode("\n", $out->messages()), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
