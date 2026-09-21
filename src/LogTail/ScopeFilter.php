<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

/**
 * CakePHP log scope filtering.
 *
 * Port of `Crustum\Speculum\Watcher\LogWatcher::isScopeEnabled()` and `normalizeScopes()`.
 * Semantics:
 *
 * - `$configured` null: all messages (default).
 * - `$configured` []: unscoped messages only.
 * - `$configured` list: messages carrying one of those scopes; unscoped messages are
 *   included only when `$includeUnscoped` is true.
 */
class ScopeFilter
{
    /**
     * Whether a message context passes the configured scopes.
     *
     * @param array<string, mixed> $context Log context (CakePHP always sets `scope`).
     * @param array<string>|null $configured Configured scopes, null for all.
     * @param bool $includeUnscoped Include unscoped messages when scopes are configured.
     * @return bool True when the message is allowed.
     */
    public static function allows(array $context, ?array $configured = null, bool $includeUnscoped = true): bool
    {
        if ($configured === null) {
            return true;
        }

        $messageScopes = static::normalize($context['scope'] ?? []);

        if ($messageScopes === []) {
            return $configured === [] ? true : $includeUnscoped;
        }

        if ($configured === []) {
            return false;
        }

        return array_intersect($messageScopes, $configured) !== [];
    }

    /**
     * Normalize a CakePHP `scope` context value to a list of names.
     *
     * @param mixed $scope Context scope value (string, list, null).
     * @return list<string> Scope names.
     */
    public static function normalize(mixed $scope): array
    {
        if (in_array($scope, [null, '', []], true)) {
            return [];
        }

        if (!is_array($scope)) {
            return [(string)$scope];
        }

        $normalized = [];
        foreach ($scope as $item) {
            if ($item === null) {
                continue;
            }

            if ($item === '') {
                continue;
            }

            $normalized[] = (string)$item;
        }

        return $normalized;
    }
}
