<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\ServeHost;

/**
 * Tests for the host flow-control state machine.
 */
class ServeHostTest extends TestCase
{
    /**
     * Malformed and unknown host lines are ignored, never fatal.
     *
     * @return void
     */
    public function testIgnoresGarbage(): void
    {
        $host = new ServeHost();

        $this->assertNull($host->apply(''));
        $this->assertNull($host->apply('not json'));
        $this->assertNull($host->apply('{"v":2,"t":"shutdown"}'));
        $this->assertNull($host->apply('{"v":1,"t":"nope"}'));
        $this->assertFalse($host->shutdownRequested());
        $this->assertTrue($host->delivers('cake_live', microtime(true)));
    }

    /**
     * set_sources gates delivery to the listed sources.
     *
     * @return void
     */
    public function testSetSources(): void
    {
        $host = new ServeHost();
        $host->apply('{"v":1,"t":"set_sources","sources":["cake_file"]}');

        $this->assertSame(['cake_file'], $host->sources());
        $this->assertFalse($host->delivers('cake_live', microtime(true)));
        $this->assertTrue($host->delivers('cake_file', microtime(true)));
    }

    /**
     * pause/resume gate delivery per source or globally.
     *
     * @return void
     */
    public function testPauseResume(): void
    {
        $host = new ServeHost();
        $now = microtime(true);

        $host->apply('{"v":1,"t":"pause","source":"cake_live"}');
        $this->assertFalse($host->delivers('cake_live', $now));
        $this->assertTrue($host->delivers('cake_file', $now));

        $host->apply('{"v":1,"t":"resume","source":"cake_live"}');
        $this->assertTrue($host->delivers('cake_live', $now));

        $host->apply('{"v":1,"t":"pause"}');
        $this->assertFalse($host->delivers('cake_live', $now));
        $this->assertFalse($host->delivers('cake_file', $now));

        $host->apply('{"v":1,"t":"resume"}');
        $this->assertTrue($host->delivers('cake_live', $now));
    }

    /**
     * clear is acknowledged (the host already cleared its own buffers).
     *
     * @return void
     */
    public function testClear(): void
    {
        $host = new ServeHost();

        $response = $host->apply('{"v":1,"t":"clear","source":"cake_live"}');

        $this->assertSame(['v' => 1, 't' => 'source_status', 'source' => 'cake_live', 'status' => 'cleared'], $response);
        $this->assertTrue($host->delivers('cake_live', microtime(true)));
    }

    /**
     * since gates older events, per source or globally; shutdown flags the loop.
     *
     * @return void
     */
    public function testSinceAndShutdown(): void
    {
        $host = new ServeHost();
        $now = microtime(true);

        $host->apply('{"v":1,"t":"since","source":"cake_live","ts":' . ($now + 100) . '}');
        $this->assertFalse($host->delivers('cake_live', $now));
        $this->assertTrue($host->delivers('cake_file', $now));

        $host->apply('{"v":1,"t":"shutdown"}');
        $this->assertTrue($host->shutdownRequested());
    }
}
