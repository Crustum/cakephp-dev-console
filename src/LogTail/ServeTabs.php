<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use Cake\Core\Configure;

/**
 * Explicit tab definitions from `DevConsole.logs.tabs` configuration.
 *
 * Shape (see `docs/working-memory/CakePHP log TUI tabs plan.md` §7):
 *
 * ```php
 * 'logs' => [
 *     'tabs' => [
 *         'payments' => ['scopes' => ['payments']],
 *         'errors'   => ['files' => ['error.log'], 'level' => 'warning'],
 *     ],
 * ]
 * ```
 *
 * `level` is a *minimum* severity (same as `logs tail --level`). The definitions are only
 * announced in `ready` — the host builds the tabs, so the collector stays stateless.
 * When nothing is configured an empty list is announced and the host falls back to
 * derived (`logConfigs`) or source tabs.
 */
class ServeTabs
{
    /**
     * Read and normalize the configured tabs.
     *
     * Invalid entries (empty title, no scopes/files/level, unknown level) are skipped —
     * a typo in config must never break the collector handshake.
     *
     * @return list<array{title: string, scopes?: list<string>, files?: list<string>, level?: string}>
     */
    public static function configured(): array
    {
        /** @var mixed $configured */
        $configured = Configure::read('DevConsole.logs.tabs');

        return static::normalize($configured);
    }

    /**
     * Normalize raw tab config to announce-ready definitions.
     *
     * @param mixed $configured Raw config value.
     * @return list<array{title: string, scopes?: list<string>, files?: list<string>, level?: string}>
     */
    public static function normalize(mixed $configured): array
    {
        if (!is_array($configured)) {
            return [];
        }

        $out = [];

        foreach ($configured as $title => $tab) {
            if (!is_string($title)) {
                continue;
            }

            if (trim($title) === '') {
                continue;
            }

            if (!is_array($tab)) {
                continue;
            }

            $definition = ['title' => trim($title)];

            $scopes = ScopeFilter::normalize($tab['scopes'] ?? null);

            if ($scopes !== []) {
                $definition['scopes'] = $scopes;
            }

            $files = [];
            $rawFiles = $tab['files'] ?? null;

            if (is_string($rawFiles)) {
                $rawFiles = [$rawFiles];
            }

            if (is_array($rawFiles)) {
                foreach ($rawFiles as $file) {
                    if (is_string($file) && trim($file) !== '') {
                        $files[] = trim($file);
                    }
                }

                $files = array_values(array_unique($files));
            }

            if ($files !== []) {
                $definition['files'] = $files;
            }

            $level = $tab['level'] ?? null;

            if (is_string($level) && Severity::known($level)) {
                $definition['level'] = strtolower($level);
            }

            if (!isset($definition['scopes']) && !isset($definition['files']) && !isset($definition['level'])) {
                continue;
            }

            $out[] = $definition;
        }

        return $out;
    }
}
