<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * Attributes `cake_live` events to a configured engine for display.
 *
 * Live records carry scopes but no file, so rows would render the raw
 * `cake_live` source plus a scope dump. The index applies the same rule as
 * the host engine tabs (level allowlist AND scope intersection) and stamps
 * `meta.engine` + `meta.file` (log basename) when one engine wins. Events
 * without a winner pass through untouched.
 */
class LiveEngineIndex
{
    /**
     * @param list<array{name: string, file: string|null, scopes: list<string>|null, levels: list<string>|null}> $engines Engine descriptions in `Log::configured()` order.
     */
    public function __construct(
        protected array $engines,
    ) {
    }

    /**
     * Build an index from the live application engine configs.
     *
     * @return static
     */
    public static function configured(): static
    {
        return new static(LogConfigs::describe());
    }

    /**
     * Stamp `meta.engine` + `meta.file` on a live event when one engine wins.
     *
     * Scoped engines beat catch-alls; the first winner in config order wins.
     *
     * Extra protocol keys (v, t, source, ts, msg, raw) pass through untouched;
     * only `meta.engine`/`meta.file` are added.
     *
     * @param array{level: string, meta: array<string, mixed>} $event Live protocol event.
     * @return array{level: string, meta: array<string, mixed>} Attributed event.
     */
    public function attribute(array $event): array
    {
        $level = strtolower($event['level']);
        $scopes = [];

        foreach ((array)($event['meta']['scopes'] ?? []) as $scope) {
            if (is_string($scope) && $scope !== '') {
                $scopes[strtolower($scope)] = true;
            }
        }

        $fallback = null;

        foreach ($this->engines as $engine) {
            $levels = $engine['levels'] ?? null;

            if (is_array($levels) && !in_array($level, $levels, true)) {
                continue;
            }

            $wanted = $engine['scopes'] ?? null;

            if (!is_array($wanted)) {
                $fallback ??= $engine;

                continue;
            }

            foreach ($wanted as $scope) {
                if (isset($scopes[strtolower($scope)])) {
                    return $this->stamp($event, $engine);
                }
            }
        }

        if (is_array($fallback)) {
            return $this->stamp($event, $fallback);
        }

        return $event;
    }

    /**
     * Stamp one event with the winning engine name and log basename.
     *
     * @param array{level: string, meta: array<string, mixed>} $event Live protocol event.
     * @param array{name: string, file: string|null, scopes: list<string>|null, levels: list<string>|null} $engine Winning engine.
     * @return array{level: string, meta: array<string, mixed>} Attributed event.
     */
    protected function stamp(array $event, array $engine): array
    {
        $event['meta']['engine'] = $engine['name'];
        $file = $engine['file'] ?? null;

        if (is_string($file) && $file !== '') {
            $base = basename($file);
            $event['meta']['file'] = str_ends_with(strtolower($base), '.log') ? $base : $base . '.log';
        }

        return $event;
    }
}
