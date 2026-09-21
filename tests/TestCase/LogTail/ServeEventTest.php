<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\ServeEvent;
use RuntimeException;

/**
 * Tests for the wire-protocol event mapping.
 */
class ServeEventTest extends TestCase
{
    /**
     * Live records map to events with ms-precision timestamps and scope metadata.
     *
     * @return void
     */
    public function testFromLogEntry(): void
    {
        $before = microtime(true);
        $entry = LogEntry::fromRecord('error', 'Payment failed', ['scope' => ['payments']]);
        $after = microtime(true);

        $event = ServeEvent::fromLogEntry($entry);

        $this->assertSame(1, $event['v']);
        $this->assertSame('event', $event['t']);
        $this->assertSame('cake_live', $event['source']);
        $this->assertSame('error', $event['level']);
        $this->assertSame('Payment failed', $event['msg']);
        $this->assertSame($entry->toJson(), $event['raw']);
        $this->assertSame(['payments'], $event['meta']['scopes']);
        $this->assertGreaterThanOrEqual($before - 1.0, $event['ts']);
        $this->assertLessThanOrEqual($after + 1.0, $event['ts']);
    }

    /**
     * Exceptions are appended to the message and carried in meta.
     *
     * @return void
     */
    public function testFromLogEntryWithException(): void
    {
        $entry = LogEntry::fromRecord('critical', 'Worker crashed', ['exception' => new RuntimeException('boom')]);

        $event = ServeEvent::fromLogEntry($entry);

        $this->assertStringContainsString('Worker crashed', $event['msg']);
        $this->assertStringContainsString('RuntimeException', $event['msg']);
        $this->assertSame('RuntimeException', $event['meta']['exception']['class']);
    }

    /**
     * TailEngine JSON lines in LOGS files map back to structured events.
     *
     * @return void
     */
    public function testFromFileLineJson(): void
    {
        $json = LogEntry::fromRecord('warning', 'Disk almost full', ['scope' => ['system']])->toJson();

        $event = ServeEvent::fromFileLine($json, 'all.log');

        $this->assertSame('cake_file', $event['source']);
        $this->assertSame('warning', $event['level']);
        $this->assertSame('Disk almost full', $event['msg']);
        $this->assertSame('all.log', $event['meta']['file']);
    }

    /**
     * Default CakePHP file format lines yield level + message + file.
     *
     * @return void
     */
    public function testFromFileLineCakeFormat(): void
    {
        $event = ServeEvent::fromFileLine('2026-09-20 12:00:00 error: Something broke', 'error.log');

        $this->assertSame('cake_file', $event['source']);
        $this->assertSame('error', $event['level']);
        $this->assertSame('Something broke', $event['msg']);
        $this->assertSame('error.log', $event['meta']['file']);
        $this->assertSame('2026-09-20 12:00:00 error: Something broke', $event['raw']);
    }

    /**
     * Unknown levels degrade to info; unparseable lines are never dropped.
     *
     * @return void
     */
    public function testFromFileLineFallback(): void
    {
        $weird = ServeEvent::fromFileLine('2026-09-20 12:00:00 bogus: ?', 'debug.log');
        $this->assertSame('info', $weird['level']);

        $garbage = ServeEvent::fromFileLine('not a log line at all', 'debug.log');
        $this->assertSame('info', $garbage['level']);
        $this->assertSame('not a log line at all', $garbage['msg']);
        $this->assertSame('not a log line at all', $garbage['raw']);
    }

    /**
     * Entry-start detection drives block stitching: heads start, trace lines continue.
     *
     * @return void
     */
    public function testIsEntryStart(): void
    {
        $json = LogEntry::fromRecord('error', 'boom')->toJson();

        $this->assertTrue(ServeEvent::isEntryStart($json));
        $this->assertTrue(ServeEvent::isEntryStart('2026-09-20 19:42:36 error: Something broke'));
        $this->assertFalse(ServeEvent::isEntryStart('Stack Trace:'));
        $this->assertFalse(ServeEvent::isEntryStart('- CORE\src\Controller\ControllerFactory.php:79'));
        $this->assertFalse(ServeEvent::isEntryStart('Request URL: /css/fonts.css'));
        $this->assertFalse(ServeEvent::isEntryStart(''));
        $this->assertFalse(ServeEvent::isEntryStart('   '));
    }

    /**
     * Stitched multi-line text keeps the head's level/ts, first-line msg, full raw.
     *
     * @return void
     */
    public function testFromFileLineMultiline(): void
    {
        $text = implode("\n", [
            '2026-09-20 19:42:36 error: Controller class `Css` could not be found.',
            'Stack Trace:',
            '- CORE\src\Controller\ControllerFactory.php:79',
            '',
            'Request URL: /css/fonts.css',
        ]);

        $event = ServeEvent::fromFileLine($text, 'error.log');

        $this->assertSame('error', $event['level']);
        $this->assertStringContainsString('Controller class `Css` could not be found.', $event['msg']);
        $this->assertStringContainsString('Request URL: /css/fonts.css', $event['msg']);
        $this->assertStringContainsString('Stack Trace:', $event['raw']);
        $this->assertStringContainsString('Request URL: /css/fonts.css', $event['raw']);
    }
}
