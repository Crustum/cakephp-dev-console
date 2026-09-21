<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\LogEntry;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Tests for the log entry value object.
 */
class LogEntryTest extends TestCase
{
    /**
     * Whether this test bound a Router request (needs reload cleanup).
     *
     * @var bool
     */
    protected bool $routerTouched = false;

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        if ($this->routerTouched) {
            Router::reload();
            $this->routerTouched = false;
        }

        parent::tearDown();
    }

    /**
     * Records split scopes, keep the rest as context, and stamp the time.
     *
     * @return void
     */
    public function testFromRecord(): void
    {
        $entry = LogEntry::fromRecord('ERROR', 'Payment failed', ['scope' => ['payments'], 'amount' => 100]);

        $this->assertSame('Payment failed', $entry->message());
        $this->assertSame('error', $entry->level());
        $this->assertSame(['payments'], $entry->scopes());
        $this->assertSame(['amount' => 100], $entry->context());
        $this->assertNull($entry->exception());
        $this->assertSame('ERROR', $entry->classOrType());
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $entry->time());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry->date());
    }

    /**
     * PSR-3 placeholders are interpolated from context (like FileLog), so
     * live rows match the file output instead of showing raw templates.
     *
     * @return void
     */
    public function testFromRecordInterpolatesPlaceholders(): void
    {
        $entry = LogEntry::fromRecord(
            'debug',
            'connection={connection} role={role} duration={took} rows={num} {missing}',
            ['connection' => 'default', 'role' => 'write', 'took' => 1.1, 'num' => 1],
        );

        $this->assertSame(
            'connection=default role=write duration=1.1 rows=1 {missing}',
            $entry->message(),
        );
    }

    /**
     * Throwables become exception metadata (the TUI keeps them inline).
     *
     * @return void
     */
    public function testFromRecordWithException(): void
    {
        $exception = new RuntimeException('Boom');
        $entry = LogEntry::fromRecord('error', 'Failed', ['exception' => $exception]);

        $this->assertSame([], $entry->context());
        $this->assertSame('RuntimeException', $entry->classOrType());
        $this->assertSame(__FILE__, $entry->file());

        $details = $entry->exception();
        $this->assertNotNull($details);
        $this->assertSame(RuntimeException::class, $details['class']);
        $this->assertSame('Boom', $details['message']);
        $this->assertNotEmpty($details['trace']);
    }

    /**
     * Unserializable context degrades instead of throwing.
     *
     * @return void
     */
    public function testFromRecordSanitizesContext(): void
    {
        $resource = fopen('php://memory', 'rb');
        $entry = LogEntry::fromRecord('info', 'Hi', [
            'resource' => $resource,
            'object' => new stdClass(),
            'nested' => ['deep' => ['deeper' => ['deepest' => new stdClass()]]],
        ]);
        fclose($resource);

        $context = $entry->context();
        $this->assertStringStartsWith('[', (string)$context['resource']);
        $this->assertStringStartsWith('[', (string)$context['object']);

        $json = $entry->toJson();
        $this->assertJson($json);
    }

    /**
     * HTTP origin is captured from the server environment.
     *
     * @return void
     */
    public function testFromRecordCapturesHttpOrigin(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/payments?x=1';

        $entry = LogEntry::fromRecord('info', 'Hi');

        $this->assertSame('http', $entry->origin()['type']);
        $this->assertSame('POST', $entry->origin()['method']);
        $this->assertSame('/payments', $entry->origin()['path']);
        $this->assertNull($entry->origin()['auth']);
    }

    /**
     * The bound Router request wins over globals and carries the identity.
     *
     * @return void
     */
    public function testFromRecordCapturesRouterIdentity(): void
    {
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'url' => '/orders',
        ]))->withAttribute('identity', ['id' => 7, 'email' => 'a@b.c']);
        Router::setRequest($request);
        $this->routerTouched = true;

        $entry = LogEntry::fromRecord('info', 'Hi');

        $origin = $entry->origin();
        $this->assertSame('http', $origin['type']);
        $this->assertSame('GET', $origin['method']);
        $this->assertSame('/orders', $origin['path']);
        $this->assertSame(7, $origin['auth']);
        $this->assertSame('a@b.c', $origin['auth_email']);
    }

    /**
     * Object identities work through getIdentifier/get duck-typing (no plugin dependency).
     *
     * @return void
     */
    public function testFromRecordCapturesObjectIdentity(): void
    {
        $identity = new class {
            /**
             * @return int
             */
            public function getIdentifier(): int
            {
                return 42;
            }

            /**
             * @param string $field Field name.
             * @return string|null
             */
            public function get(string $field): ?string
            {
                return $field === 'email' ? 'u@x.y' : null;
            }
        };
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'url' => '/',
        ]))->withAttribute('identity', $identity);
        Router::setRequest($request);
        $this->routerTouched = true;

        $entry = LogEntry::fromRecord('info', 'Hi');

        $this->assertSame(42, $entry->origin()['auth']);
        $this->assertSame('u@x.y', $entry->origin()['auth_email']);
    }

    /**
     * Anonymous requests and foreign identity shapes degrade to nulls, never throw.
     *
     * @return void
     */
    public function testFromRecordAnonymousIdentity(): void
    {
        $request = new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'url' => '/',
        ]);
        Router::setRequest($request);
        $this->routerTouched = true;

        $entry = LogEntry::fromRecord('info', 'Hi');

        $this->assertNull($entry->origin()['auth']);
        $this->assertNull($entry->origin()['auth_email']);
    }

    /**
     * JSON round-trips losslessly; malformed input throws.
     *
     * @return void
     */
    public function testJsonRoundTrip(): void
    {
        $entry = LogEntry::fromRecord('warning', 'Slow', ['scope' => 'orders']);
        $restored = LogEntry::fromJson($entry->toJson());

        $this->assertSame('Slow', $restored->message());
        $this->assertSame('warning', $restored->level());
        $this->assertSame(['orders'], $restored->scopes());

        $this->expectException(JsonException::class);
        LogEntry::fromJson('not json');
    }

    /**
     * Entries missing message or datetime are rejected.
     *
     * @return void
     */
    public function testFromJsonRejectsShape(): void
    {
        $this->expectException(JsonException::class);
        LogEntry::fromJson('{"level":"info"}');
    }

    /**
     * The haystack covers message, level, scopes, context and exception class.
     *
     * @return void
     */
    public function testHaystack(): void
    {
        $entry = LogEntry::fromRecord('error', 'Charge declined', ['scope' => ['payments'], 'code' => 'card_declined']);

        $haystack = $entry->haystack();
        $this->assertStringContainsString('charge declined', $haystack);
        $this->assertStringContainsString('payments', $haystack);
        $this->assertStringContainsString('card_declined', $haystack);
    }

    /**
     * Non-UTF8 bytes and non-finite floats never break the JSON line.
     *
     * @return void
     */
    public function testToJsonSurvivesBinaryPayloads(): void
    {
        $entry = LogEntry::fromRecord(
            'error',
            "binary \xff\xfe prefix",
            ['payload' => "raw \xff bytes", 'ratio' => INF, 'missing' => NAN],
        );

        $json = $entry->toJson();
        $back = LogEntry::fromJson($json);

        $this->assertStringContainsString('binary', $back->message());
        $this->assertSame('INF', $back->context()['ratio']);
        $this->assertSame('NAN', $back->context()['missing']);
    }
}
