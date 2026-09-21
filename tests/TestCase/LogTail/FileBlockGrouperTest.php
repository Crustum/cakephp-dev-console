<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\FileBlockGrouper;

/**
 * Tests for file-log block stitching.
 */
class FileBlockGrouperTest extends TestCase
{
    /**
     * An exception block (head + trace + request lines) completes on the next head.
     *
     * @return void
     */
    public function testExceptionBlockStitches(): void
    {
        $grouper = new FileBlockGrouper();

        $this->assertSame([], $grouper->push('error.log', '2026-09-20 19:42:36 error: Controller gone'));
        $this->assertSame([], $grouper->push('error.log', 'Stack Trace:'));
        $this->assertSame([], $grouper->push('error.log', '- CORE\src\Controller\ControllerFactory.php:79'));
        $this->assertSame([], $grouper->push('error.log', ''));
        $this->assertSame([], $grouper->push('error.log', 'Request URL: /css/fonts.css'));

        $done = $grouper->push('error.log', '2026-09-20 19:43:04 error: Speculum dropping stale entries.');

        $this->assertCount(1, $done);
        $this->assertSame('error.log', $done[0]['file']);
        $this->assertStringContainsString('Controller gone', $done[0]['text']);
        $this->assertStringContainsString('Stack Trace:', $done[0]['text']);
        $this->assertStringContainsString('Request URL: /css/fonts.css', $done[0]['text']);

        $tail = $grouper->flushAll();

        $this->assertCount(1, $tail);
        $this->assertStringContainsString('Speculum dropping', $tail[0]['text']);
        $this->assertSame([], $grouper->flushAll());
    }

    /**
     * Stray continuations (backfill cut mid-block) form their own block; stray blanks drop.
     *
     * @return void
     */
    public function testStrayLines(): void
    {
        $grouper = new FileBlockGrouper();

        $this->assertSame([], $grouper->push('error.log', ''));
        $this->assertSame([], $grouper->push('error.log', '- orphan frame'));

        $tail = $grouper->flushAll();

        $this->assertCount(1, $tail);
        $this->assertSame('- orphan frame', $tail[0]['text']);
    }

    /**
     * Files group independently: a new head only flushes its own file.
     *
     * @return void
     */
    public function testFilesAreIndependent(): void
    {
        $grouper = new FileBlockGrouper();

        $this->assertSame([], $grouper->push('a.log', '2026-09-20 19:42:36 error: First'));
        $this->assertSame([], $grouper->push('b.log', '2026-09-20 19:42:37 error: Second'));

        $tail = $grouper->flushAll();

        $this->assertCount(2, $tail);
        $this->assertSame('a.log', $tail[0]['file']);
        $this->assertSame('b.log', $tail[1]['file']);
    }

    /**
     * Runaway blocks flush early instead of gluing a whole file into one event.
     *
     * @return void
     */
    public function testCapsForceFlush(): void
    {
        $grouper = new FileBlockGrouper(3, 1000000);

        $this->assertSame([], $grouper->push('error.log', '2026-09-20 19:42:36 error: Head'));
        $this->assertSame([], $grouper->push('error.log', 'continued 1'));

        $done = $grouper->push('error.log', 'continued 2');

        $this->assertCount(1, $done);
        $this->assertStringContainsString('Head', $done[0]['text']);
    }
}
