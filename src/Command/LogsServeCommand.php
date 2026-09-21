<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Crustum\DevConsole\LogTail\BackfillDedup;
use Crustum\DevConsole\LogTail\FileBlockGrouper;
use Crustum\DevConsole\LogTail\FileTail;
use Crustum\DevConsole\LogTail\LiveEngineIndex;
use Crustum\DevConsole\LogTail\LogConfigs;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\Parser;
use Crustum\DevConsole\LogTail\ServeEvent;
use Crustum\DevConsole\LogTail\ServeHost;
use Crustum\DevConsole\LogTail\ServeTabs;
use Crustum\DevConsole\LogTail\SessionFiles;
use Crustum\DevConsole\LogTail\TailEngine;
use Override;
use Throwable;

/**
 * Collect application logs and stream them to the TUI host as NDJSON.
 *
 * Speaks `workspace/log-tui/PROTOCOL.md` (v1) on stdio: `ready` + `event`
 * messages on stdout, host control messages on stdin. Usually spawned by
 * `bin/cake logs tui`, but `bin/cake logs serve --timeout=5 | head` is handy
 * for debugging the protocol itself.
 *
 * The collector is stateless by design: live engine tap + `LOGS/*.log`
 * backfill/tail, straight to stdout. It never filters, counts or buffers —
 * the host owns all of that. Host `pause`/`resume`/`set_sources`/`since`
 * only gate delivery.
 *
 * Usage: `bin/cake logs serve [--tail=200] [--sources=cake_live,cake_file]`
 */
class LogsServeCommand extends Command
{
    /**
     * Poll interval in microseconds.
     *
     * @var int
     */
    protected const POLL_INTERVAL = 100000;

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Stream application logs to the TUI host (NDJSON on stdio)';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('tail', [
                'default' => '200',
                'help' => 'Backfill lines per file source (0 for live only)',
            ])
            ->addOption('sources', [
                'help' => 'Comma-separated sources (default: cake_live,cake_file)',
            ])
            ->addOption('timeout', [
                'default' => '0',
                'help' => 'Stop after this many seconds (0 for unlimited)',
            ]);
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @param \Cake\Console\ConsoleIo $io Console I/O (stderr diagnostics only; the protocol goes to raw stdout).
     * @return int Exit code.
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $tail = $this->intOption($args->getOption('tail'), 200);

        if ($tail === null || $tail < 0) {
            $io->error('Invalid --tail. Use a non-negative integer.');

            return static::CODE_ERROR;
        }

        $timeout = $this->intOption($args->getOption('timeout'), 0);

        if ($timeout === null || $timeout < 0) {
            $io->error('Invalid --timeout. Use a non-negative integer.');

            return static::CODE_ERROR;
        }

        $sources = $this->parseSources($args->getOption('sources'));

        if ($sources === []) {
            $io->error('Invalid --sources. List at least one source.');

            return static::CODE_ERROR;
        }

        TailEngine::register();

        $sessions = SessionFiles::configured();
        $session = $sessions->create();
        $host = new ServeHost($sources);
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

        $in = $this->openInputStream();
        $out = $this->openOutputStream();

        if (!is_resource($in) || !is_resource($out)) {
            $sessions->destroy($session);
            $io->error('Could not open stdio streams.');

            return static::CODE_ERROR;
        }

        stream_set_blocking($in, false);

        $pollStdin = $this->stdinPollable($in);

        $reason = 'shutdown';

        try {
            $this->emit($out, [
                'v' => ServeEvent::VERSION,
                't' => 'ready',
                'sources' => $sources,
                'cwd' => (string)getcwd(),
                'logConfigs' => LogConfigs::describe(),
                'tabs' => ServeTabs::configured(),
            ]);

            foreach (array_diff($sources, ServeEvent::SOURCES) as $unknown) {
                $this->emit($out, [
                    'v' => ServeEvent::VERSION,
                    't' => 'source_error',
                    'source' => $unknown,
                    'error' => 'unknown source (v1 knows: ' . implode(', ', ServeEvent::SOURCES) . ')',
                ]);
            }

            $fileTail = new FileTail($this->logFiles());
            $liveTail = new FileTail([$session]);
            $parser = new Parser();
            $stdinBuffer = '';
            $controlBuffer = '';
            $logFiles = $this->logFiles();
            $blocks = new FileBlockGrouper();
            $dedup = new BackfillDedup();
            $liveEngines = LiveEngineIndex::configured();

            $control = $this->connectControl();

            if (in_array('cake_file', $sources, true)) {
                if ($logFiles === []) {
                    $this->emit($out, [
                        'v' => ServeEvent::VERSION,
                        't' => 'source_error',
                        'source' => 'cake_file',
                        'error' => 'no log files found',
                    ]);
                }

                foreach ($fileTail->backfill($tail) as $backfill) {
                    foreach ($blocks->push($backfill['file'], $backfill['line']) as $block) {
                        $event = $this->emitFileEvent($block, $host, $out);

                        if ($event !== null) {
                            $dedup->remember($event['level'], $event['msg'], (float)$event['ts']);
                        }
                    }
                }

                foreach ($blocks->flushAll() as $block) {
                    $event = $this->emitFileEvent($block, $host, $out);

                    if ($event !== null) {
                        $dedup->remember($event['level'], $event['msg'], (float)$event['ts']);
                    }
                }

                $this->flush($out);
            }

            $deadline = $timeout > 0 ? microtime(true) + $timeout : 0.0;

            while (true) {
                if ($stopped) {
                    $reason = 'signal';

                    break;
                }

                $paced = false;

                if ($control !== null) {
                    $paced = true;

                    if ($this->pumpControl($control)) {
                        $controlBuffer .= $this->readAvailable($control);

                        $closed = $this->pumpHost($control, $controlBuffer, $host, $out);

                        if ($host->shutdownRequested()) {
                            $reason = 'shutdown';

                            break;
                        }

                        if ($closed) {
                            $reason = 'control-closed';

                            break;
                        }
                    }
                } elseif ($pollStdin) {
                    $stdinBuffer .= $this->readAvailable($in);

                    $eof = $this->pumpHost($in, $stdinBuffer, $host, $out);

                    if ($host->shutdownRequested()) {
                        $reason = 'shutdown';

                        break;
                    }

                    if ($eof) {
                        $reason = 'eof';

                        break;
                    }
                }

                if (in_array('cake_live', $host->sources(), true)) {
                    $this->drainLive($liveTail, $parser, $host, $out, $dedup, $liveEngines);
                }

                $this->flush($out);

                if ($deadline > 0.0 && microtime(true) >= $deadline) {
                    $reason = 'timeout';

                    break;
                }

                $this->dispatchSignals();

                if (!$paced) {
                    usleep(static::POLL_INTERVAL);
                }
            }

            $this->emit($out, ['v' => ServeEvent::VERSION, 't' => 'bye', 'reason' => $reason]);
            $this->flush($out);

            return static::CODE_SUCCESS;
        } finally {
            $sessions->destroy($session);
        }
    }

    /**
     * Emit one stitched file block as a protocol event when delivered.
     *
     * Flushed every poll tick: FileLog writes one record in one write, so a
     * block lands whole and per-tick flushing keeps latency under one interval.
     *
     * @param array{file: string, text: string} $block Stitched block.
     * @param \Crustum\DevConsole\LogTail\ServeHost $host Delivery gates.
     * @param mixed $out Output stream.
     * @return array{v: int, t: string, source: string, ts: float, level: string, msg: string, raw: string, meta: array<string, mixed>}|null Event when delivered.
     */
    protected function emitFileEvent(array $block, ServeHost $host, mixed $out): ?array
    {
        $event = ServeEvent::fromFileLine($block['text'], $block['file']);

        if (!$host->delivers('cake_file', (float)$event['ts'])) {
            return null;
        }

        $this->emit($out, $event);

        return $event;
    }

    /**
     * Drain new session-file lines into `cake_live` events.
     *
     * Skipped lines aggregate into one `dropped` message per call: the serve
     * child's stderr is inherited by the fullscreen TUI, so per-line warnings
     * would scribble over the rendered UI. Backfill replays are skipped
     * silently through the dedup window.
     *
     * @param \Crustum\DevConsole\LogTail\FileTail $liveTail Session tailer.
     * @param \Crustum\DevConsole\LogTail\Parser $parser Line parser.
     * @param \Crustum\DevConsole\LogTail\ServeHost $host Delivery gates.
     * @param mixed $out Output stream.
     * @param \Crustum\DevConsole\LogTail\BackfillDedup|null $dedup Backfill replay filter, if any.
     * @param \Crustum\DevConsole\LogTail\LiveEngineIndex|null $engines Engine attribution for display labels, if any.
     * @return void
     */
    protected function drainLive(
        FileTail $liveTail,
        Parser $parser,
        ServeHost $host,
        mixed $out,
        ?BackfillDedup $dedup = null,
        ?LiveEngineIndex $engines = null,
    ): void {
        $polled = $liveTail->poll();
        $skipped = 0;

        foreach ($polled['lines'] as $found) {
            $entry = $parser->parse($found['line']);

            if (!$entry instanceof LogEntry) {
                if (trim($found['line']) !== '') {
                    $skipped++;
                }

                continue;
            }

            if ($dedup instanceof BackfillDedup && $dedup->isDuplicate($entry->level(), $entry->message(), $entry->timestamp())) {
                continue;
            }

            $event = ServeEvent::fromLogEntry($entry);

            if ($engines instanceof LiveEngineIndex) {
                $attributed = $engines->attribute($event);
                $event['level'] = $attributed['level'];
                $event['meta'] = $attributed['meta'];
            }

            if ($host->delivers('cake_live', (float)$event['ts'])) {
                $this->emit($out, $event);
            }
        }

        if ($skipped > 0) {
            $this->emit($out, [
                'v' => ServeEvent::VERSION,
                't' => 'dropped',
                'source' => 'cake_live',
                'count' => $skipped,
            ]);
        }
    }

    /**
     * Emit one protocol message as NDJSON.
     *
     * @param mixed $out Output stream.
     * @param array<string, mixed> $message Message.
     * @return void
     */
    protected function emit(mixed $out, array $message): void
    {
        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($line)) {
            return;
        }

        set_error_handler(static fn(): bool => true);
        try {
            fwrite($out, $line . "\n");
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Flush the output stream (once per tick, not per line).
     *
     * @param mixed $out Output stream.
     * @return void
     */
    protected function flush(mixed $out): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            fflush($out);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether stdin supports pollable reads.
     *
     * `stream_select()` fails on Windows anonymous pipes (and
     * `stream_set_blocking()` is a no-op there), so a blind `fread()` would
     * block forever. Probe once with a zero timeout instead of guessing.
     * Windows throws `ValueError` for non-socket streams instead of returning
     * false; either way stdin is unpollable there.
     *
     * @param mixed $in Input stream.
     * @return bool True when stdin can be polled without blocking.
     */
    protected function stdinPollable(mixed $in): bool
    {
        if (!is_resource($in)) {
            return false;
        }

        set_error_handler(static fn(): bool => true);
        try {
            $read = [$in];
            $write = [];
            $except = [];

            try {
                $ready = stream_select($read, $write, $except, 0, 0);
            } catch (Throwable) {
                return false;
            }
        } finally {
            restore_error_handler();
        }

        return $ready !== false;
    }

    /**
     * Dial back to the host's control server (see PROTOCOL.md).
     *
     * The host passes `CAKE_LOGS_CONTROL_PORT`/`CAKE_LOGS_CONTROL_TOKEN` in
     * the child's environment; the first line sent is the token. Loopback TCP
     * sockets are selectable on every platform, which stdio pipes are not on
     * Windows. Null when not offered (manual runs, old hosts) or unreachable
     * — the loop then falls back to legacy stdin polling.
     *
     * @return mixed Connected non-blocking socket, or null.
     */
    protected function connectControl(): mixed
    {
        $port = getenv('CAKE_LOGS_CONTROL_PORT');
        $token = getenv('CAKE_LOGS_CONTROL_TOKEN');

        if (!is_string($port) || $port === '' || !is_string($token) || $token === '') {
            return null;
        }

        set_error_handler(static fn(): bool => true);
        try {
            $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($socket)) {
            return null;
        }

        stream_set_blocking($socket, false);

        set_error_handler(static fn(): bool => true);
        try {
            $written = fwrite($socket, $token . "\n");
        } finally {
            restore_error_handler();
        }

        if ($written !== strlen($token) + 1) {
            fclose($socket);

            return null;
        }

        return $socket;
    }

    /**
     * Wait up to one poll interval for control input (this paces the loop).
     *
     * @param mixed $control Control socket.
     * @return bool True when bytes may be available (or the socket failed).
     */
    protected function pumpControl(mixed $control): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            $read = [$control];
            $write = [];
            $except = [];

            try {
                $ready = stream_select($read, $write, $except, 0, static::POLL_INTERVAL);
            } catch (Throwable) {
                return true;
            }
        } finally {
            restore_error_handler();
        }

        return $ready !== false && $ready > 0;
    }

    /**
     * Pump complete host lines from a stream buffer through ServeHost.
     *
     * Shared by the stdin and control-socket paths; the caller decides what
     * EOF means for its transport.
     *
     * @param mixed $stream Stream the buffer was read from.
     * @param string $buffer Buffered bytes (updated in place).
     * @param \Crustum\DevConsole\LogTail\ServeHost $host Delivery gates.
     * @param mixed $out Output stream.
     * @return bool True when the stream hit EOF.
     */
    protected function pumpHost(mixed $stream, string &$buffer, ServeHost $host, mixed $out): bool
    {
        $complete = explode("\n", $buffer);
        $buffer = array_pop($complete);

        foreach ($complete as $line) {
            $response = $host->apply($line);

            if ($response !== null) {
                $this->emit($out, $response);
            }
        }

        return is_resource($stream) && feof($stream);
    }

    /**
     * Read whatever is available on stdin without blocking.
     *
     * @param mixed $in Input stream.
     * @return string Bytes read (empty when none).
     */
    protected function readAvailable(mixed $in): string
    {
        set_error_handler(static fn(): bool => true);
        try {
            $chunk = fread($in, 65536);
        } finally {
            restore_error_handler();
        }

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * Dispatch pending signals when pcntl is available.
     *
     * @return void
     */
    protected function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Open the stdin stream (overridable in tests).
     *
     * @return mixed
     */
    protected function openInputStream(): mixed
    {
        return fopen('php://stdin', 'rb');
    }

    /**
     * Open the stdout stream (overridable in tests).
     *
     * @return mixed
     */
    protected function openOutputStream(): mixed
    {
        return fopen('php://stdout', 'wb');
    }

    /**
     * Log files backing the `cake_file` source, sorted.
     *
     * Overridable via `Configure::write('DevConsole.serveLogsDir', $dir)` (used by tests).
     *
     * @return list<string> Absolute paths.
     */
    protected function logFiles(): array
    {
        /** @var mixed $configured */
        $configured = Configure::read('DevConsole.serveLogsDir');

        $dir = is_string($configured) && $configured !== ''
            ? $configured
            : (defined('LOGS') ? constant('LOGS') : '');

        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.log') ?: [];
        sort($files);

        return array_values(array_filter($files, is_file(...)));
    }

    /**
     * Parse the `--sources` option.
     *
     * @param string|bool|null $value Raw option value.
     * @return list<string>
     */
    protected function parseSources(string|bool|null $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return ServeEvent::SOURCES;
        }

        $sources = array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn(string $source): bool => $source !== '',
        ));

        return $sources === [] ? ServeEvent::SOURCES : $sources;
    }

    /**
     * Narrow a CLI option value to an int.
     *
     * @param string|bool|null $value Raw option value.
     * @param int $default Default when the option was not passed.
     * @return int|null Null when the value is not numeric.
     */
    protected function intOption(string|bool|null $value, int $default): ?int
    {
        if ($value === null || $value === false) {
            return $default;
        }

        if (!is_string($value) || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }
}
