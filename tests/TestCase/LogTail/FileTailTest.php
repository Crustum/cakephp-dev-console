<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\FileTail;

/**
 * Tests for the multi-file tailer.
 */
class FileTailTest extends TestCase
{
    /**
     * Working directory for fixture logs.
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

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_filetail_' . uniqid();
        mkdir($this->dir, 0775, true);
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

        parent::tearDown();
    }

    /**
     * Write lines to a fixture file.
     *
     * @param string $name Basename.
     * @param list<string> $lines Lines.
     * @return string Absolute path.
     */
    protected function fixture(string $name, array $lines): string
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($file, implode("\n", $lines) . "\n");

        return $file;
    }

    /**
     * Backfill returns the last N lines per file, oldest first, then live starts at the end.
     *
     * @return void
     */
    public function testBackfillThenPoll(): void
    {
        $file = $this->fixture('error.log', ['one', 'two', 'three']);
        $tail = new FileTail([$file]);

        $backfill = $tail->backfill(2);

        $this->assertSame([
            ['file' => 'error.log', 'line' => 'two'],
            ['file' => 'error.log', 'line' => 'three'],
        ], $backfill);

        // Live starts where backfill ended: only appended lines arrive.
        file_put_contents($file, "four\n", FILE_APPEND);
        $polled = $tail->poll();

        $this->assertSame([], $polled['reopened']);
        $this->assertSame([['file' => 'error.log', 'line' => 'four']], $polled['lines']);
    }

    /**
     * Rotation (replace = new inode, smaller size) reopens from the start.
     *
     * @return void
     */
    public function testRotation(): void
    {
        $file = $this->fixture('debug.log', ['old content with padding']);
        $tail = new FileTail([$file]);
        $tail->backfill(10);

        unlink($file);
        file_put_contents($file, "new\n");

        $polled = $tail->poll();

        $this->assertSame(['debug.log'], $polled['reopened']);
        $this->assertSame([['file' => 'debug.log', 'line' => 'new']], $polled['lines']);
    }

    /**
     * Missing files backfill empty and poll skips them without failing.
     *
     * @return void
     */
    public function testMissingFile(): void
    {
        $tail = new FileTail([$this->dir . DIRECTORY_SEPARATOR . 'nope.log']);

        $this->assertSame([], $tail->backfill(10));
        $this->assertSame(['lines' => [], 'reopened' => []], $tail->poll());
    }
}
