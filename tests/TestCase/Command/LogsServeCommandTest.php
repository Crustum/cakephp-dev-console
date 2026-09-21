<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Closure;
use Crustum\DevConsole\Command\LogsServeCommand;
use Crustum\DevConsole\LogTail\BackfillDedup;
use Crustum\DevConsole\LogTail\FileTail;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\Parser;
use Crustum\DevConsole\LogTail\ServeHost;
use Crustum\DevConsole\LogTail\TailEngine;

/**
 * Tests for the logs serve collector.
 */
class LogsServeCommandTest extends TestCase
{
    /**
     * Isolated session directory.
     *
     * @var string
     */
    protected string $dir = '';

    /**
     * Isolated logs directory.
     *
     * @var string
     */
    protected string $logs = '';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_serve_' . uniqid();
        $this->logs = $this->dir . DIRECTORY_SEPARATOR . 'logs';
        mkdir($this->logs, 0775, true);
        Configure::write('DevConsole.tailPath', $this->dir);
        Configure::write('DevConsole.serveLogsDir', $this->logs);
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

        foreach (glob($this->logs . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->logs)) {
            rmdir($this->logs);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        Configure::delete('DevConsole.tailPath');
        Configure::delete('DevConsole.serveLogsDir');
        Configure::delete('DevConsole.logs');

        parent::tearDown();
    }

    /**
     * Build a command with in-memory stdio.
     *
     * @param string $stdin Bytes available on stdin.
     * @param mixed $out Output stream (or null for a fresh memory stream).
     * @param bool $pollStdin Whether stdin reports as pollable (false simulates Windows pipes).
     * @return array{command: \Crustum\DevConsole\Command\LogsServeCommand, out: mixed}
     */
    protected function command(string $stdin, mixed $out = null, bool $pollStdin = true): array
    {
        $in = fopen('php://memory', 'rb+');
        assert(is_resource($in));
        fwrite($in, $stdin);
        rewind($in);

        $out ??= fopen('php://memory', 'wb+');
        assert(is_resource($out));

        $command = new class ($in, $out, $pollStdin) extends LogsServeCommand {
            /**
             * @param mixed $in Input stream.
             * @param mixed $out Output stream.
             * @param bool $pollStdin Whether stdin reports as pollable.
             */
            public function __construct(
                protected mixed $in,
                protected mixed $out,
                protected bool $pollStdin = true,
            ) {
                parent::__construct();
            }

            /**
             * @inheritDoc
             */
            protected function openInputStream(): mixed
            {
                return $this->in;
            }

            /**
             * @inheritDoc
             */
            protected function openOutputStream(): mixed
            {
                return $this->out;
            }

            /**
             * Force the pollable flag: memory streams are unpollable on
             * Windows, so tests pin the mode instead of probing the stream.
             *
             * @param mixed $in Input stream.
             * @return bool Pinned mode.
             */
            protected function stdinPollable(mixed $in): bool
            {
                return $this->pollStdin;
            }
        };

        return ['command' => $command, 'out' => $out];
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
            'timeout' => '0',
        ], $options), []);
    }

    /**
     * Read emitted protocol messages from the output stream.
     *
     * @param mixed $out Output stream.
     * @return list<array<string, mixed>>
     */
    protected function messages(mixed $out): array
    {
        rewind($out);
        $contents = stream_get_contents($out);

        $messages = [];

        foreach (explode("\n", (string)$contents) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $messages[] = json_decode($line, true);
        }

        return $messages;
    }

    /**
     * Invalid options fail before any protocol output.
     *
     * @return void
     */
    public function testInvalidOptionsFail(): void
    {
        foreach ([['tail' => 'bogus'], ['tail' => '-1']] as $options) {
            ['command' => $command, 'out' => $out] = $this->command('');

            $code = $command->execute(
                $this->arguments($options),
                new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput()),
            );

            $this->assertSame(LogsServeCommand::CODE_ERROR, $code);
            $this->assertSame([], $this->messages($out));

            fclose($out);
        }
    }

    /**
     * Shutdown on stdin ends the session: ready (+backfill) then bye, session cleaned up.
     *
     * @return void
     */
    public function testShutdownRoundtrip(): void
    {
        file_put_contents(
            $this->logs . DIRECTORY_SEPARATOR . 'error.log',
            "2026-09-20 12:00:00 error: Something broke\n",
        );
        Configure::write('DevConsole.logs.tabs', [
            'errors' => ['files' => ['error.log'], 'level' => 'warning'],
        ]);

        ['command' => $command, 'out' => $out] = $this->command('{"v":1,"t":"shutdown"}' . "\n");

        $code = $command->execute(
            $this->arguments(),
            new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput()),
        );

        $this->assertSame(LogsServeCommand::CODE_SUCCESS, $code);

        $messages = $this->messages($out);
        fclose($out);

        $this->assertSame('ready', $messages[0]['t']);
        $this->assertSame(1, $messages[0]['v']);
        $this->assertSame(['cake_live', 'cake_file'], $messages[0]['sources']);
        $this->assertArrayHasKey('logConfigs', $messages[0]);
        $this->assertSame(
            [['title' => 'errors', 'files' => ['error.log'], 'level' => 'warning']],
            $messages[0]['tabs'],
        );

        $kinds = array_column($messages, 't');
        $this->assertContains('event', $kinds);
        $this->assertSame('bye', end($messages)['t']);
        $this->assertSame('shutdown', end($messages)['reason']);

        $event = $messages[array_search('event', $kinds, true)];
        $this->assertSame('cake_file', $event['source']);
        $this->assertSame('error', $event['level']);
        $this->assertSame('Something broke', $event['msg']);

        // Session file is destroyed even though the loop ran.
        $this->assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*.tail') ?: []);
    }

    /**
     * Unpollable stdin (Windows pipes) skips host reads instead of blocking:
     * the loop still runs and ends by timeout, ignoring the queued shutdown.
     *
     * @return void
     */
    public function testUnpollableStdinSkipsHostReads(): void
    {
        ['command' => $command, 'out' => $out] = $this->command('{"v":1,"t":"shutdown"}' . "\n", null, false);

        $code = $command->execute(
            $this->arguments(['timeout' => '1']),
            new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput()),
        );

        $this->assertSame(LogsServeCommand::CODE_SUCCESS, $code);

        $messages = $this->messages($out);
        fclose($out);

        $this->assertSame('ready', $messages[0]['t']);
        $this->assertSame('bye', end($messages)['t']);
        $this->assertSame('timeout', end($messages)['reason']);
    }

    /**
     * The real probe never throws and returns a bool on any stream.
     *
     * @return void
     */
    public function testStdinPollableProbeIsSafe(): void
    {
        // Plain command: the anonymous test subclass pins the flag, so only
        // an un-overridden instance exercises the real probe.
        $plain = new LogsServeCommand();
        $probe = Closure::bind(
            fn(mixed $in): bool => $this->stdinPollable($in),
            $plain,
            LogsServeCommand::class,
        );

        $in = fopen('php://memory', 'rb');
        assert(is_resource($in));

        try {
            // true on pollable platforms, false on Windows — never a throw.
            $this->assertIsBool($probe($in));
            $this->assertFalse($probe(null));
        } finally {
            fclose($in);
        }
    }

    /**
     * Control dial-back reaches a real loopback server with the token first line.
     *
     * @return void
     */
    public function testControlDialAndToken(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server);

        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $port = substr(strrchr($name, ':'), 1);

        putenv('CAKE_LOGS_CONTROL_PORT=' . $port);
        putenv('CAKE_LOGS_CONTROL_TOKEN=s3cr3t');

        $plain = new LogsServeCommand();
        $connect = Closure::bind(
            fn(): mixed => $this->connectControl(),
            $plain,
            LogsServeCommand::class,
        );

        try {
            $client = $connect();
            $this->assertIsResource($client);

            $peer = stream_socket_accept($server, 5);
            $this->assertIsResource($peer);

            $line = fgets($peer);
            $this->assertSame("s3cr3t\n", $line);

            fclose($peer);
            fclose($client);
        } finally {
            putenv('CAKE_LOGS_CONTROL_PORT');
            putenv('CAKE_LOGS_CONTROL_TOKEN');
            fclose($server);
        }
    }

    /**
     * Missing/unreachable control env falls back to null (legacy stdin path).
     *
     * @return void
     */
    public function testControlDialFailureFallsBack(): void
    {
        $plain = new LogsServeCommand();
        $connect = Closure::bind(
            fn(): mixed => $this->connectControl(),
            $plain,
            LogsServeCommand::class,
        );

        putenv('CAKE_LOGS_CONTROL_PORT');
        putenv('CAKE_LOGS_CONTROL_TOKEN');
        $this->assertNull($connect());

        putenv('CAKE_LOGS_CONTROL_PORT=1');
        putenv('CAKE_LOGS_CONTROL_TOKEN=x');

        try {
            $this->assertNull($connect());
        } finally {
            putenv('CAKE_LOGS_CONTROL_PORT');
            putenv('CAKE_LOGS_CONTROL_TOKEN');
        }
    }

    /**
     * A multi-line file exception (trace + request lines) stays one error event.
     *
     * @return void
     */
    public function testMultilineFileExceptionStaysOneEvent(): void
    {
        file_put_contents(
            $this->logs . DIRECTORY_SEPARATOR . 'error.log',
            implode("\n", [
                '2026-09-20 19:42:36 error: Controller class `Css` could not be found.',
                'Stack Trace:',
                '- CORE\src\Controller\ControllerFactory.php:79',
                '',
                'Request URL: /css/fonts.css',
                '',
            ]),
        );

        ['command' => $command, 'out' => $out] = $this->command('{"v":1,"t":"shutdown"}' . "\n");

        $code = $command->execute(
            $this->arguments(),
            new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput()),
        );

        $this->assertSame(LogsServeCommand::CODE_SUCCESS, $code);

        $messages = $this->messages($out);
        fclose($out);

        $events = array_values(array_filter($messages, static fn(array $m): bool => $m['t'] === 'event'));

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['level']);
        $this->assertStringContainsString('Controller class `Css` could not be found.', $events[0]['msg']);
        $this->assertStringContainsString('Stack Trace:', $events[0]['raw']);
        $this->assertStringContainsString('Request URL: /css/fonts.css', $events[0]['raw']);
    }

    /**
     * Malformed live lines aggregate into one `dropped` message (never stderr).
     *
     * @return void
     */
    public function testDrainLiveSkipsAggregateToDropped(): void
    {
        $session = $this->dir . DIRECTORY_SEPARATOR . 'drain.tail';
        $good = LogEntry::fromRecord('info', 'hello')->toJson();
        file_put_contents($session, "not json at all\n{$good}\n");

        $command = new LogsServeCommand();
        $drain = Closure::bind(
            function (FileTail $tail, Parser $parser, ServeHost $host, mixed $out): void {
                $this->drainLive($tail, $parser, $host, $out);
            },
            $command,
            LogsServeCommand::class,
        );

        $out = fopen('php://memory', 'wb+');
        assert(is_resource($out));

        try {
            $drain(new FileTail([$session]), new Parser(), new ServeHost(['cake_live']), $out);

            rewind($out);
            $rows = array_values(array_filter(
                explode("\n", (string)stream_get_contents($out)),
                static fn(string $line): bool => trim($line) !== '',
            ));
        } finally {
            fclose($out);
        }

        $this->assertCount(2, $rows);

        $first = json_decode($rows[0], true);
        $second = json_decode($rows[1], true);

        $this->assertSame('event', $first['t']);
        $this->assertSame('hello', $first['msg']);
        $this->assertSame('dropped', $second['t']);
        $this->assertSame('cake_live', $second['source']);
        $this->assertSame(1, $second['count']);
    }

    /**
     * A live replay of a backfilled record is suppressed silently.
     *
     * @return void
     */
    public function testDrainLiveSuppressesBackfillReplay(): void
    {
        $entry = LogEntry::fromRecord('info', 'hello');
        $session = $this->dir . DIRECTORY_SEPARATOR . 'replay.tail';
        file_put_contents($session, $entry->toJson() . "\n");

        $dedup = new BackfillDedup();
        $dedup->remember($entry->level(), $entry->message(), $entry->timestamp());

        $command = new LogsServeCommand();
        $drain = Closure::bind(
            function (FileTail $tail, Parser $parser, ServeHost $host, mixed $out, ?BackfillDedup $dedup): void {
                $this->drainLive($tail, $parser, $host, $out, $dedup);
            },
            $command,
            LogsServeCommand::class,
        );

        $out = fopen('php://memory', 'wb+');
        assert(is_resource($out));

        try {
            $drain(new FileTail([$session]), new Parser(), new ServeHost(['cake_live']), $out, $dedup);

            rewind($out);
            $contents = (string)stream_get_contents($out);
        } finally {
            fclose($out);
        }

        $this->assertSame('', trim($contents));
    }

    /**
     * Unknown sources are reported, and EOF on stdin ends the loop.
     *
     * @return void
     */
    public function testUnknownSourceAndEof(): void
    {
        ['command' => $command, 'out' => $out] = $this->command('');

        $code = $command->execute(
            $this->arguments(['sources' => 'cake_live,nope']),
            new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput()),
        );

        $this->assertSame(LogsServeCommand::CODE_SUCCESS, $code);

        $messages = $this->messages($out);
        fclose($out);

        $this->assertSame('ready', $messages[0]['t']);

        $error = $messages[1];
        $this->assertSame('source_error', $error['t']);
        $this->assertSame('nope', $error['source']);

        $this->assertSame('bye', end($messages)['t']);
        $this->assertSame('eof', end($messages)['reason']);
    }
}
