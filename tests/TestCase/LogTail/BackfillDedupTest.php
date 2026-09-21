<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\BackfillDedup;

/**
 * Tests for the backfill/live boundary dedup.
 */
class BackfillDedupTest extends TestCase
{
    /**
     * A remembered backfill event suppresses exactly one live replay.
     *
     * @return void
     */
    public function testSuppressesOneReplayPerRemember(): void
    {
        $dedup = new BackfillDedup();
        $dedup->remember('error', 'Boom', 1726844800.5);

        $this->assertTrue($dedup->isDuplicate('error', 'Boom', 1726844800.9));
        $this->assertFalse($dedup->isDuplicate('error', 'Boom', 1726844800.9));
    }

    /**
     * Level, message head and second all take part in the signature.
     *
     * @return void
     */
    public function testSignatureParts(): void
    {
        $dedup = new BackfillDedup();
        $dedup->remember('error', "Head\nTail", 100.0);

        $this->assertTrue($dedup->isDuplicate('ERROR', "Head\nOther tail", 100.9));
    }

    /**
     * Different level, head or second never match.
     *
     * @return void
     */
    public function testNoMatch(): void
    {
        $dedup = new BackfillDedup();
        $dedup->remember('error', 'Boom', 100.0);

        $this->assertFalse($dedup->isDuplicate('info', 'Boom', 100.0));
        $this->assertFalse($dedup->isDuplicate('error', 'Other', 100.0));
        $this->assertFalse($dedup->isDuplicate('error', 'Boom', 101.0));
    }

    /**
     * Identical records in the same second suppress one by one.
     *
     * @return void
     */
    public function testRepeatedRecords(): void
    {
        $dedup = new BackfillDedup();
        $dedup->remember('error', 'Same', 100.0);
        $dedup->remember('error', 'Same', 100.0);
        $dedup->remember('error', 'Same', 100.0);

        $this->assertTrue($dedup->isDuplicate('error', 'Same', 100.0));
        $this->assertTrue($dedup->isDuplicate('error', 'Same', 100.0));
        $this->assertTrue($dedup->isDuplicate('error', 'Same', 100.0));
        $this->assertFalse($dedup->isDuplicate('error', 'Same', 100.0));
    }

    /**
     * Past the window every live record passes and memory is released.
     *
     * @return void
     */
    public function testWindowExpiry(): void
    {
        $dedup = new BackfillDedup(0);
        $dedup->remember('error', 'Boom', 100.0);

        $this->assertFalse($dedup->isDuplicate('error', 'Boom', 100.0));
    }
}
