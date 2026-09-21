<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use Cake\Console\Arguments;

/**
 * Tail filter options.
 *
 * Contains minimum level, substring filters over message and
 * whole record, plus CakePHP scope filtering.
 */
class Options
{
    /**
     * @param int $timeout Maximum run time in seconds, 0 exits immediately.
     * @param int $lines Exit after this many printed lines, 0 for unlimited.
     * @param string|null $level Minimum level name (any case), null for all.
     * @param string|null $filter Substring matched against the whole record.
     * @param string|null $message Substring matched against the message.
     * @param array<string>|null $scopes Scope filter, null for all.
     * @param bool $includeUnscoped Include unscoped messages when scopes are set.
     */
    public function __construct(
        protected int $timeout,
        protected int $lines = 0,
        protected ?string $level = null,
        protected ?string $filter = null,
        protected ?string $message = null,
        protected ?array $scopes = null,
        protected bool $includeUnscoped = true,
    ) {
    }

    /**
     * Build options from console arguments.
     *
     * `--scope` accepts a comma-separated list (`--scope=payments,orders`).
     *
     * @param \Cake\Console\Arguments $args Parsed arguments.
     * @return static Options.
     */
    public static function fromArguments(Arguments $args): static
    {
        $scope = $args->getOption('scope');
        $scopes = null;
        if (is_string($scope) && trim($scope) !== '') {
            $scopes = array_values(array_filter(array_map(trim(...), explode(',', $scope))));
        }

        return new static(
            (int)$args->getOption('timeout'),
            (int)$args->getOption('lines'),
            self::stringOption($args->getOption('level')),
            self::stringOption($args->getOption('filter')),
            self::stringOption($args->getOption('message')),
            $scopes,
        );
    }

    /**
     * Whether the options accept the given entry.
     *
     * @param \Crustum\DevConsole\LogTail\LogEntry $entry Entry.
     * @return bool True when the entry passes every active filter.
     */
    public function accepts(LogEntry $entry): bool
    {
        if (is_string($this->level) && !Severity::meets($entry->level(), $this->level)) {
            return false;
        }

        if (!ScopeFilter::allows(['scope' => $entry->scopes()], $this->scopes, $this->includeUnscoped)) {
            return false;
        }

        if (is_string($this->filter) && !str_contains($entry->haystack(), strtolower($this->filter))) {
            return false;
        }

        return !is_string($this->message) || str_contains(strtolower($entry->message()), strtolower($this->message));
    }

    /**
     * Maximum run time in seconds.
     *
     * @return int
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Exit after this many printed lines (0 = unlimited).
     *
     * @return int
     */
    public function lines(): int
    {
        return $this->lines;
    }

    /**
     * Narrow a raw option value to a non-empty string.
     *
     * @param mixed $value Raw option value.
     * @return string|null
     */
    protected static function stringOption(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
