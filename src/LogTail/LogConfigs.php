<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use Cake\Log\Log;
use Throwable;

/**
 * Describes the application's `Log` engine configs for the TUI host.
 *
 * The host derives default tabs from this (one per engine: name + file + scopes + level
 * band), so `app.php` never has to be re-declared for the viewer. Only tab-relevant,
 * non-sensitive keys are exposed — never `url`, `className` or other engine options.
 */
class LogConfigs
{
    /**
     * Describe all configured engines except our own tail engine.
     *
     * Levels are lowercased; null means "all levels". Scopes null means "all scopes".
     *
     * @return list<array{name: string, file: string|null, scopes: list<string>|null, levels: list<string>|null}>
     */
    public static function describe(): array
    {
        $out = [];

        try {
            $names = Log::configured();
        } catch (Throwable) {
            return [];
        }

        foreach ($names as $name) {
            if ($name === TailEngine::CONFIG_KEY) {
                continue;
            }

            try {
                $config = Log::getConfig($name);
            } catch (Throwable) {
                continue;
            }

            if (!is_array($config)) {
                continue;
            }

            /** @var mixed $file */
            $file = $config['file'] ?? null;

            $out[] = [
                'name' => $name,
                'file' => is_string($file) && $file !== '' ? $file : null,
                'scopes' => static::scopes($config['scopes'] ?? null),
                'levels' => static::levels($config['levels'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Normalize an engine `scopes` value. Null/false = all scopes (no constraint).
     *
     * @param mixed $scopes Raw config value.
     * @return list<string>|null
     */
    protected static function scopes(mixed $scopes): ?array
    {
        if ($scopes === null || $scopes === false) {
            return null;
        }

        $normalized = ScopeFilter::normalize($scopes);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Normalize an engine `levels` value. Null = all levels (no constraint).
     *
     * @param mixed $levels Raw config value.
     * @return list<string>|null
     */
    protected static function levels(mixed $levels): ?array
    {
        if ($levels === null || $levels === false) {
            return null;
        }

        if (is_string($levels)) {
            $levels = [$levels];
        }

        if (!is_array($levels)) {
            return null;
        }

        $normalized = [];
        foreach ($levels as $level) {
            if (!is_string($level)) {
                continue;
            }

            if ($level === '') {
                continue;
            }

            $level = strtolower($level);

            if (Severity::known($level)) {
                $normalized[] = $level;
            }
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized === [] ? null : $normalized;
    }
}
