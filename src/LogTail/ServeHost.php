<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * Tracks host flow-control for the `logs serve` collector (`PROTOCOL.md`, v1).
 *
 * The collector stays stateless in the content sense (no filtering, counting
 * or buffering): this only records *delivery* gates — which sources the host
 * wants, which are paused, and per-source `since` watermarks. Malformed or
 * unknown host lines are ignored, never fatal.
 */
class ServeHost
{
    /**
     * @var list<string> Active sources.
     */
    protected array $sources = [];

    /**
     * @var array<string, true> Paused sources.
     */
    protected array $paused = [];

    /**
     * @var array<string, float> Per-source minimum timestamps.
     */
    protected array $since = [];

    /**
     * @var bool Shutdown requested.
     */
    protected bool $shutdown = false;

    /**
     * @param list<string> $sources Initially active sources.
     */
    public function __construct(array $sources = ['cake_live', 'cake_file'])
    {
        foreach ($sources as $source) {
            $this->sources[] = $source;
        }
    }

    /**
     * Apply one host line. Returns a response message to emit, if any.
     *
     * `clear` has no server-side buffer, so it is acknowledged with a
     * `source_status` message (the host already cleared its own buffers).
     *
     * @param string $line Raw stdin line.
     * @return array<string, mixed>|null Response message, if any.
     */
    public function apply(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $decoded = json_decode($line, true);

        if (!is_array($decoded) || ($decoded['v'] ?? null) !== ServeEvent::VERSION || !is_string($decoded['t'] ?? null)) {
            return null;
        }

        switch ($decoded['t']) {
            case 'set_sources':
                $sources = $decoded['sources'] ?? null;

                if (is_array($sources)) {
                    $this->sources = array_values(array_filter(
                        array_map(strval(...), $sources),
                        static fn(string $source): bool => $source !== '',
                    ));
                }

                return null;
            case 'pause':
                $this->pause($decoded['source'] ?? null);

                return null;
            case 'resume':
                $this->resume($decoded['source'] ?? null);

                return null;
            case 'clear':
                $source = isset($decoded['source']) ? (string)$decoded['source'] : null;

                return [
                    'v' => ServeEvent::VERSION,
                    't' => 'source_status',
                    'source' => $source ?? '*',
                    'status' => 'cleared',
                ];
            case 'since':
                $ts = $decoded['ts'] ?? null;

                if (is_numeric($ts)) {
                    $source = isset($decoded['source']) ? (string)$decoded['source'] : null;

                    if ($source === null || $source === '') {
                        foreach ($this->sources as $active) {
                            $this->since[$active] = (float)$ts;
                        }
                    } else {
                        $this->since[$source] = (float)$ts;
                    }
                }

                return null;
            case 'shutdown':
                $this->shutdown = true;

                return null;
            default:
                return null;
        }
    }

    /**
     * Whether an event should be delivered (active, unpaused, past `since`).
     *
     * @param string $source Source name.
     * @param float $ts Event timestamp.
     * @return bool
     */
    public function delivers(string $source, float $ts): bool
    {
        if (!in_array($source, $this->sources, true)) {
            return false;
        }

        if (isset($this->paused[$source])) {
            return false;
        }

        return $ts >= ($this->since[$source] ?? 0.0);
    }

    /**
     * Whether shutdown was requested.
     *
     * @return bool
     */
    public function shutdownRequested(): bool
    {
        return $this->shutdown;
    }

    /**
     * Active sources.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Pause one source (or all when null).
     *
     * @param mixed $source Source name, if any.
     * @return void
     */
    protected function pause(mixed $source): void
    {
        if (is_string($source) && $source !== '') {
            $this->paused[$source] = true;

            return;
        }

        foreach ($this->sources as $active) {
            $this->paused[$active] = true;
        }
    }

    /**
     * Resume one source (or all when null).
     *
     * @param mixed $source Source name, if any.
     * @return void
     */
    protected function resume(mixed $source): void
    {
        if (is_string($source) && $source !== '') {
            unset($this->paused[$source]);

            return;
        }

        $this->paused = [];
    }
}
