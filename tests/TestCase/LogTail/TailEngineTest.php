<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\SessionFiles;
use Crustum\DevConsole\LogTail\TailEngine;

/**
 * Tests for the catch-all log engine.
 */
class TailEngineTest extends TestCase
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

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_engine_test_' . uniqid();
        Configure::write('DevConsole.tailPath', $this->dir);
        TailEngine::register();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        TailEngine::unregister();

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
     * Registration is idempotent and catch-all.
     *
     * @return void
     */
    public function testRegister(): void
    {
        TailEngine::register();
        TailEngine::register();

        /** @var \Crustum\DevConsole\LogTail\TailEngine $engine */
        $engine = Log::engine(TailEngine::CONFIG_KEY);
        $this->assertInstanceOf(TailEngine::class, $engine);
        $this->assertSame([], $engine->levels());
        $this->assertSame([], $engine->scopes());
    }

    /**
     * Inactive engine is a no-op; active engine broadcasts JSON lines to every session.
     *
     * @return void
     */
    public function testLogBroadcastsToSessions(): void
    {
        $sessions = SessionFiles::configured();

        Log::write('info', 'Nobody listens');
        $this->assertSame([], $sessions->all());

        $first = $sessions->create();
        $second = $sessions->create();

        Log::write('error', 'Boom', ['scope' => ['payments']]);

        foreach ([$first, $second] as $file) {
            $entry = LogEntry::fromJson(trim((string)file_get_contents($file)));
            $this->assertSame('Boom', $entry->message());
            $this->assertSame('error', $entry->level());
            $this->assertSame(['payments'], $entry->scopes());
        }

        $sessions->destroy($first);
        $sessions->destroy($second);
    }

    /**
     * String scopes land in the entry.
     *
     * @return void
     */
    public function testStringScope(): void
    {
        $sessions = SessionFiles::configured();
        $file = $sessions->create();

        Log::error('Scoped', ['scope' => 'orders']);

        $entry = LogEntry::fromJson(trim((string)file_get_contents($file)));
        $this->assertSame(['orders'], $entry->scopes());

        $sessions->destroy($file);
    }
}
