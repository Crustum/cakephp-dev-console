<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\SessionFiles;

/**
 * Tests for the session-file bridge.
 */
class SessionFilesTest extends TestCase
{
    /**
     * Isolated session directory.
     *
     * @var string
     */
    protected string $dir = '';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_tail_test_' . uniqid();
        Configure::write('DevConsole.tailPath', $this->dir);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        Configure::delete('DevConsole.tailPath');

        parent::tearDown();
    }

    /**
     * Inactive without sessions; create/all/broadcast/destroy round-trip.
     *
     * @return void
     */
    public function testLifecycle(): void
    {
        $sessions = SessionFiles::configured();

        $this->assertFalse($sessions->isActive());
        $this->assertSame([], $sessions->all());

        $first = $sessions->create();
        $second = $sessions->create();

        $this->assertTrue($sessions->isActive());
        $this->assertSame([$first, $second], $sessions->all());

        $sessions->broadcast('{"a":1}');
        $this->assertSame("{\"a\":1}\n", (string)file_get_contents($first));
        $this->assertSame("{\"a\":1}\n", (string)file_get_contents($second));

        $sessions->destroy($first);
        $this->assertSame([$second], $sessions->all());

        $sessions->destroy($second);
        $this->assertFalse($sessions->isActive());
    }

    /**
     * Broadcast tolerates sessions vanishing mid-write.
     *
     * @return void
     */
    public function testBroadcastIgnoresFailures(): void
    {
        $sessions = SessionFiles::configured();
        $file = $sessions->create();
        unlink($file);

        $sessions->broadcast('{"a":1}');

        $this->assertTrue(true);
    }

    /**
     * Sessions untouched past the TTL are pruned as corpses.
     *
     * @return void
     */
    public function testPrunesStaleSessions(): void
    {
        $sessions = SessionFiles::configured();
        $stale = $sessions->create();
        $fresh = $sessions->create();
        touch($stale, time() - SessionFiles::STALE_TTL - 10);

        $this->assertSame(1, $sessions->prune());
        $this->assertFileDoesNotExist($stale);
        $this->assertSame([$fresh], $sessions->all());

        // Pruning also runs on create, so corpses never accumulate.
        touch($fresh, time() - SessionFiles::STALE_TTL - 10);
        $next = $sessions->create();
        $this->assertFileDoesNotExist($fresh);
        $this->assertSame([$next], $sessions->all());
    }

    /**
     * Broadcast trims session files back under the size cap.
     *
     * @return void
     */
    public function testBroadcastTrimsOversizedSessions(): void
    {
        $sessions = SessionFiles::configured();
        $file = $sessions->create();

        $line = str_repeat('x', 999) . "\n";
        $repeats = (int)(SessionFiles::MAX_BYTES / 1000) + 10;
        file_put_contents($file, str_repeat($line, $repeats), LOCK_EX);
        $this->assertGreaterThan(SessionFiles::MAX_BYTES, (int)filesize($file));

        $sessions->broadcast('{"a":1}');

        $size = (int)filesize($file);
        $this->assertLessThanOrEqual(SessionFiles::MAX_BYTES + 16, $size);

        $contents = (string)file_get_contents($file);
        $this->assertStringEndsWith("{\"a\":1}\n", $contents);
        $this->assertStringStartsWith('x', ltrim($contents, "\n"));
    }
}
