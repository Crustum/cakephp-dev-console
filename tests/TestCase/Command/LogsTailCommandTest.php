<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Command\LogsTailCommand;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\Options;
use Crustum\DevConsole\LogTail\Parser;
use Crustum\DevConsole\LogTail\Reader;
use Crustum\DevConsole\LogTail\SessionFiles;
use Crustum\DevConsole\LogTail\TailEngine;

/**
 * Tests for the logs tail command.
 */
class LogsTailCommandTest extends TestCase
{
    /**
     * Isolated session directory.
     *
     * @var string
     */
    protected string $dir = '';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_cmd_test_' . uniqid();
        Configure::write('DevConsole.tailPath', $this->dir);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        TailEngine::unregister();

        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        Configure::delete('DevConsole.tailPath');

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
            'filter' => null,
            'message' => null,
            'level' => null,
            'scope' => null,
            'timeout' => '0',
            'lines' => '0',
        ], $options), []);
    }

    /**
     * Unknown levels are rejected before tailing starts.
     *
     * @return void
     */
    public function testInvalidLevelFails(): void
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);

        $code = (new LogsTailCommand())->execute($this->arguments(['level' => 'bogus']), $io);

        $this->assertSame(LogsTailCommand::CODE_ERROR, $code);
        $this->assertSame([], SessionFiles::configured()->all());
    }

    /**
     * Drain-only run prints the banner, exits successfully and cleans up its session.
     *
     * @return void
     */
    public function testDrainRun(): void
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);

        $code = (new LogsTailCommand())->execute($this->arguments(), $io);

        $this->assertSame(LogsTailCommand::CODE_SUCCESS, $code);
        $this->assertSame([], SessionFiles::configured()->all());

        $text = implode("\n", $out->messages());
        $this->assertStringContainsString('Tailing application logs', $text);
    }

    /**
     * End to end: engine record flows through a session into printed output.
     *
     * @return void
     */
    public function testEngineToOutput(): void
    {
        TailEngine::register();
        $sessions = SessionFiles::configured();
        $file = $sessions->create();

        Log::write('warning', 'Disk almost full', ['scope' => ['system']]);

        $entries = [];
        $parser = new Parser();
        $options = new Options(0);

        (new Reader($file, true))->follow(
            function (string $line) use ($options, $parser, &$entries): bool {
                $entry = $parser->parse($line);

                if ($entry instanceof LogEntry && $options->accepts($entry)) {
                    $entries[] = $entry;
                }

                return true;
            },
            0,
        );

        $sessions->destroy($file);

        $this->assertCount(1, $entries);
        $this->assertSame('Disk almost full', $entries[0]->message());
        $this->assertSame(['system'], $entries[0]->scopes());
    }
}
