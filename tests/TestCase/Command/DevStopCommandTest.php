<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Command\DevStopCommand;
use Crustum\DevConsole\Process\Processes;

/**
 * Tests for the dev stop command.
 */
class DevStopCommandTest extends TestCase
{
    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * No pid file means nothing to stop.
     *
     * @return void
     */
    public function testMissingPidFile(): void
    {
        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);

        $code = (new DevStopCommand())->execute(new Arguments([], ['timeout' => '1'], []), $io);

        $this->assertSame(DevStopCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('No dev processes are running', implode("\n", $out->messages()));
    }

    /**
     * A stale pid file is removed without killing anything.
     *
     * @return void
     */
    public function testStalePidFile(): void
    {
        file_put_contents(Processes::pidFile(), '2147483647');

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);

        $code = (new DevStopCommand())->execute(new Arguments([], ['timeout' => '1'], []), $io);

        $this->assertSame(DevStopCommand::CODE_SUCCESS, $code);
        $this->assertFalse(is_file(Processes::pidFile()));
    }

    /**
     * A live supervised process is stopped and the pid file removed.
     *
     * Spawns a real sleeper, so stop exercises the graceful-then-force path
     * end to end on the current OS.
     *
     * @return void
     */
    public function testStopsLiveProcess(): void
    {
        $sleeper = PHP_OS_FAMILY === 'Windows' ? 'ping -n 30 127.0.0.1' : 'sleep 30';
        $process = proc_open($sleeper, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        $this->assertIsResource($process);

        try {
            $status = proc_get_status($process);
            $pid = $status['pid'];

            $this->assertGreaterThan(0, $pid);
            $this->assertTrue(Processes::isPidRunning($pid));

            file_put_contents(Processes::pidFile(), (string)$pid);

            $out = new StubConsoleOutput();
            $io = new ConsoleIo($out, $out);

            $code = (new DevStopCommand())->execute(new Arguments([], ['timeout' => '3'], []), $io);

            $this->assertSame(DevStopCommand::CODE_SUCCESS, $code);
            $this->assertFalse(Processes::isPidRunning($pid));
            $this->assertFalse(is_file(Processes::pidFile()));
        } finally {
            $status = proc_get_status($process);

            if ($status['running']) {
                Processes::killTree($status['pid'], true);
            }

            proc_close($process);

            $file = Processes::pidFile();

            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
