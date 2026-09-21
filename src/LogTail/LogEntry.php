<?php
declare(strict_types=1);

namespace Crustum\DevConsole\LogTail;

use ArrayObject;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use DateTimeImmutable;
use JsonException;
use JsonSerializable;
use Serializable;
use Stringable;
use Throwable;

/**
 * A single captured log record.
 *
 * JSON shape (one object per line, mirrors the engine output):
 *
 * ```json
 * {"message": "...", "datetime": "2026-09-20T12:00:00.123456+00:00", "level": "error",
 *  "scopes": ["payments"], "context": {"amount": 100},
 *  "exception": {"class": "...", "message": "...", "file": "...", "line": 12, "trace": [...]},
 *  "origin": {"type": "http", "method": "GET", "path": "/payments"}}
 * ```
 */
class LogEntry
{
    /**
     * @param string $message Interpolated message.
     * @param string $datetime ISO8601 datetime with microseconds.
     * @param string $level Lowercase level name.
     * @param list<string> $scopes CakePHP scopes.
     * @param array<string, mixed> $context Sanitized JSON-safe context (no `scope`, no objects).
     * @param array{class: string, message: string, file: string, line: int, trace: list<array{file: string, line: int|null}>}|null $exception Exception details, if any.
     * @param array<string, mixed> $origin Capture origin (http/console details).
     */
    protected function __construct(
        protected string $message,
        protected string $datetime,
        protected string $level,
        protected array $scopes = [],
        protected array $context = [],
        protected ?array $exception = null,
        protected array $origin = [],
    ) {
    }

    /**
     * Build an entry from a live `BaseLog::log()` call.
     *
     * The message template is interpolated from context first (PSR-3
     * `{placeholder}` substitution, mirroring `BaseLog::interpolate()` as
     * `FileLog` does), so live rows match the file output. Without this the
     * TUI showed raw templates like `connection={connection}`.
     *
     * Never throws: interpolation and context sanitization degrade gracefully
     * because logging must never break the application being observed.
     *
     * @param string $level Level name.
     * @param \Stringable|string $message Message template.
     * @param array<string, mixed> $context Log context.
     * @return static Entry.
     */
    public static function fromRecord(string $level, string|Stringable $message, array $context = []): static
    {
        $message = static::interpolate($message, $context);
        $scopes = ScopeFilter::normalize($context['scope'] ?? []);
        unset($context['scope']);

        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $exception = static::exceptionDetails($context['exception']);
            unset($context['exception']);
        }

        $now = microtime(true);
        $datetime = date('Y-m-d\TH:i:s', (int)$now)
            . sprintf('.%06d', (int)(($now - (int)$now) * 1000000))
            . date('P', (int)$now);

        return new static(
            static::utf8($message),
            $datetime,
            strtolower($level),
            $scopes,
            static::sanitize($context),
            $exception,
            static::captureOrigin(),
        );
    }

    /**
     * Decode an entry from one JSON line.
     *
     * @param string $json JSON line.
     * @return static Entry.
     * @throws \JsonException On malformed input.
     */
    public static function fromJson(string $json): static
    {
        /** @var array{message?: mixed, datetime?: mixed, level?: mixed, scopes?: mixed, context?: mixed, exception?: mixed, origin?: mixed} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_string($data['message'] ?? null) || !is_string($data['datetime'] ?? null)) {
            throw new JsonException('Log entry is missing message or datetime.');
        }

        $scopes = is_array($data['scopes'] ?? null) ? array_values($data['scopes']) : [];
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $origin = is_array($data['origin'] ?? null) ? $data['origin'] : [];
        $exception = is_array($data['exception'] ?? null) ? $data['exception'] : null;

        return new static(
            $data['message'],
            $data['datetime'],
            strtolower((string)($data['level'] ?? 'info')),
            $scopes,
            $context,
            $exception,
            $origin,
        );
    }

    /**
     * Encode the entry as one JSON line.
     *
     * @return string JSON line without trailing newline.
     */
    public function toJson(): string
    {
        $json = json_encode([
            'message' => $this->message,
            'datetime' => $this->datetime,
            'level' => $this->level,
            'scopes' => $this->scopes,
            'context' => $this->context,
            'exception' => $this->exception,
            'origin' => $this->origin,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : '{"message":"","datetime":"","level":"info"}';
    }

    /**
     * Message text.
     *
     * @return string
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * Full date (`Y-m-d H:i:s`), for verbose output.
     *
     * @return string
     */
    public function date(): string
    {
        $time = strtotime($this->datetime);

        return $time === false ? $this->datetime : date('Y-m-d H:i:s', $time);
    }

    /**
     * Short time (`H:i:s`), for compact output.
     *
     * @return string
     */
    public function time(): string
    {
        $time = strtotime($this->datetime);

        return $time === false ? $this->datetime : date('H:i:s', $time);
    }

    /**
     * Unix timestamp with fractional seconds, for the TUI wire protocol.
     *
     * @return float Seconds with ms precision.
     */
    public function timestamp(): float
    {
        try {
            return (float)(new DateTimeImmutable($this->datetime))->format('U.u');
        } catch (Throwable) {
            return microtime(true);
        }
    }

    /**
     * Lowercase level name.
     *
     * @return string
     */
    public function level(): string
    {
        return $this->level;
    }

    /**
     * CakePHP scopes.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * Sanitized context (no scope, no exception object).
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Exception details, if the record carried a Throwable.
     *
     * @return array{class: string, message: string, file: string, line: int, trace: list<array{file: string, line: int|null}>}|null
     */
    public function exception(): ?array
    {
        return $this->exception;
    }

    /**
     * Exception file, null when the record carries no exception.
     *
     * @return string|null
     */
    public function file(): ?string
    {
        return $this->exception['file'] ?? null;
    }

    /**
     * Badge text: exception short class or uppercased level.
     *
     * @return string
     */
    public function classOrType(): string
    {
        if ($this->exception !== null) {
            $parts = explode('\\', $this->exception['class']);

            return end($parts);
        }

        return strtoupper($this->level);
    }

    /**
     * Capture origin.
     *
     * @return array<string, mixed>
     */
    public function origin(): array
    {
        return $this->origin;
    }

    /**
     * Haystack for substring `--filter` matching: message plus context.
     *
     * @return string Lowercase searchable text.
     */
    public function haystack(): string
    {
        $parts = [$this->message, $this->level, implode(' ', $this->scopes)];

        if ($this->context !== []) {
            $parts[] = (string)json_encode($this->context);
        }

        if ($this->exception !== null) {
            $parts[] = $this->exception['class'] . ' ' . $this->exception['message'];
        }

        return strtolower(implode(' ', $parts));
    }

    /**
     * Extract JSON-safe exception details with a bounded trace.
     *
     * @param \Throwable $exception Exception.
     * @return array{class: string, message: string, file: string, line: int, trace: list<array{file: string, line: int|null}>}
     */
    protected static function exceptionDetails(Throwable $exception): array
    {
        $trace = [];
        foreach ($exception->getTrace() as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            $trace[] = ['file' => $frame['file'], 'line' => $frame['line'] ?? null];

            if (count($trace) >= 20) {
                break;
            }
        }

        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $trace,
        ];
    }

    /**
     * Capture where this record was emitted (http request or console command).
     *
     * HTTP detection prefers the bound Router request (set on every middleware run,
     * so it carries attributes like the authentication `identity`), falling back to
     * server globals. Console origin comes from argv. Anything unexpected degrades
     * to an anonymous console origin — capture must never throw.
     *
     * @return array<string, mixed>
     */
    protected static function captureOrigin(): array
    {
        try {
            $request = Router::getRequest();
            if ($request instanceof ServerRequest) {
                return static::httpOrigin(
                    $request->getMethod(),
                    $request->getPath(),
                    $request->getAttribute('identity'),
                );
            }
        } catch (Throwable) {
            // Fall through to the globals below.
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        if (is_string($method) && $method !== '') {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $path = is_string($uri) ? (string)parse_url($uri, PHP_URL_PATH) : '/';

            return static::httpOrigin($method, $path === '' ? '/' : $path, null);
        }

        $argv = $_SERVER['argv'] ?? null;
        if (is_array($argv) && isset($argv[1]) && is_string($argv[1])) {
            return ['type' => 'console', 'command' => $argv[1]];
        }

        if (is_array($argv) && isset($argv[0]) && is_string($argv[0]) && $argv[0] !== '') {
            return ['type' => 'console', 'command' => basename($argv[0])];
        }

        return ['type' => 'console', 'command' => null];
    }

    /**
     * Build an http origin with best-effort auth details.
     *
     * @param string $method Request method.
     * @param string $path Request path.
     * @param mixed $identity `identity` request attribute, if any.
     * @return array{type: string, method: string, path: string, auth: int|string|null, auth_email: string|null}
     */
    protected static function httpOrigin(string $method, string $path, mixed $identity): array
    {
        [$id, $email] = static::identityDetails($identity);

        return [
            'type' => 'http',
            'method' => strtoupper($method),
            'path' => $path,
            'auth' => $id,
            'auth_email' => $email,
        ];
    }

    /**
     * Extract a user id and email from an identity attribute without depending on
     * any authentication plugin: `getIdentifier()` (authentication plugin), array
     * shape (`['id', 'email']`), or generic `get($field)` accessors.
     *
     * @param mixed $identity Identity attribute value.
     * @return array{int|string|null, string|null} [id, email].
     */
    protected static function identityDetails(mixed $identity): array
    {
        try {
            if ($identity === null) {
                return [null, null];
            }

            if (is_object($identity) && is_callable([$identity, 'getIdentifier'])) {
                $id = call_user_func([$identity, 'getIdentifier']);

                return [is_string($id) || is_int($id) ? $id : null, static::identityField($identity, 'email')];
            }

            if (is_array($identity)) {
                $id = $identity['id'] ?? null;
                $email = $identity['email'] ?? null;

                return [
                    is_string($id) || is_int($id) ? $id : null,
                    is_string($email) ? $email : null,
                ];
            }

            if (is_object($identity)) {
                return [null, static::identityField($identity, 'email')];
            }
        } catch (Throwable) {
            // Identity objects are userland code; never let them break logging.
        }

        return [null, null];
    }

    /**
     * Read one field via a generic `get($field)` accessor, if present.
     *
     * @param object $identity Identity object.
     * @param string $field Field name.
     * @return string|null Field value.
     */
    protected static function identityField(object $identity, string $field): ?string
    {
        if (!is_callable([$identity, 'get'])) {
            return null;
        }

        try {
            $value = call_user_func([$identity, 'get'], $field);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Replace PSR-3 `{placeholder}` tokens with context values.
     *
     * Mirrors `Cake\Log\Engine\BaseLog::interpolate()` (which `FileLog`
     * applies before formatting) so live records carry the same text the
     * log files do. Only placeholders present in context are replaced;
     * anything unencodable degrades to a `[unhandled value of type …]`
     * marker instead of throwing.
     *
     * @param \Stringable|string $message Message template.
     * @param array<string, mixed> $context Log context.
     * @return string Interpolated message.
     */
    protected static function interpolate(string|Stringable $message, array $context = []): string
    {
        try {
            $message = (string)$message;

            if (!str_contains($message, '{') && !str_contains($message, '}')) {
                return $message;
            }

            $found = preg_match_all(
                '/(?<!\\\\)\{([a-z0-9-_]+)\}/i',
                $message,
                $matches,
            );
            if ($found === false) {
                return $message;
            }

            $placeholders = array_intersect($matches[1], array_keys($context));
            $replacements = [];
            $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;

            foreach ($placeholders as $key) {
                try {
                    $value = $context[$key];

                    if (is_scalar($value)) {
                        $replacements['{' . $key . '}'] = (string)$value;
                        continue;
                    }

                    if (is_array($value)) {
                        $replacements['{' . $key . '}'] = json_encode($value, $jsonFlags);
                        continue;
                    }

                    if ($value instanceof JsonSerializable) {
                        $replacements['{' . $key . '}'] = json_encode($value, $jsonFlags);
                        continue;
                    }

                    if ($value instanceof ArrayObject) {
                        $replacements['{' . $key . '}'] = json_encode($value->getArrayCopy(), $jsonFlags);
                        continue;
                    }

                    if ($value instanceof Serializable) {
                        $serialized = $value->serialize();
                        $replacements['{' . $key . '}'] = (string)$serialized;
                        continue;
                    }

                    if (is_object($value)) {
                        if (method_exists($value, 'toArray')) {
                            /** @var mixed $array */
                            $array = $value->toArray();
                            $replacements['{' . $key . '}'] = is_array($array)
                                ? json_encode($array, $jsonFlags)
                                : sprintf('[%s]', get_debug_type($value));
                            continue;
                        }

                        if ($value instanceof Stringable) {
                            $replacements['{' . $key . '}'] = (string)$value;
                            continue;
                        }

                        if (method_exists($value, '__debugInfo')) {
                            $replacements['{' . $key . '}'] = json_encode($value->__debugInfo(), $jsonFlags);
                            continue;
                        }
                    }

                    $replacements['{' . $key . '}'] = sprintf('[unhandled value of type %s]', get_debug_type($value));
                } catch (Throwable) {
                    $replacements['{' . $key . '}'] = sprintf('[unhandled value of type %s]', get_debug_type($context[$key] ?? null));
                }
            }

            return str_replace(array_keys($replacements), $replacements, $message);
        } catch (Throwable) {
            try {
                return (string)$message;
            } catch (Throwable) {
                return '';
            }
        }
    }

    /**
     * Recursively sanitize context to JSON-safe values (max depth 3).
     *
     * @param array<string, mixed> $context Context.
     * @param int $depth Current depth.
     * @return array<string, mixed>
     */
    protected static function sanitize(array $context, int $depth = 0): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $clean[(string)$key] = static::sanitizeValue($value, $depth);
        }

        return $clean;
    }

    /**
     * Sanitize one context value.
     *
     * Strings are scrubbed to valid UTF-8 and non-finite floats stringified:
     * either would fail `json_encode` and drop the whole record.
     *
     * @param mixed $value Value.
     * @param int $depth Current depth.
     * @return mixed JSON-safe value.
     */
    protected static function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            if (is_string($value)) {
                return static::utf8($value);
            }

            if (is_float($value) && !is_finite($value)) {
                return (string)$value;
            }

            return $value;
        }

        if ($value instanceof Throwable) {
            return 'Throwable: ' . $value::class . ': ' . $value->getMessage();
        }

        if ($depth >= 3) {
            return sprintf('[%s]', get_debug_type($value));
        }

        if (is_array($value)) {
            return static::sanitize($value, $depth + 1);
        }

        if (!is_object($value)) {
            return sprintf('[%s]', get_debug_type($value));
        }

        if ($value instanceof Stringable) {
            return static::utf8((string)$value);
        }

        if (method_exists($value, 'toArray')) {
            /** @var mixed $array */
            $array = $value->toArray();

            return is_array($array) ? static::sanitize($array, $depth + 1) : sprintf('[%s]', get_debug_type($value));
        }

        return sprintf('[%s]', get_debug_type($value));
    }

    /**
     * Scrub a string to valid UTF-8 (binary bytes become `?`), so one bad
     * request payload can never fail the record's `json_encode`.
     *
     * @param string $text Raw text.
     * @return string Valid UTF-8 text.
     */
    protected static function utf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
