<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\Severity;

/**
 * Tests for the log level severity map.
 */
class SeverityTest extends TestCase
{
    /**
     * Known levels score in PSR severity order.
     *
     * @return void
     */
    public function testValuesFollowSeverityOrder(): void
    {
        $this->assertSame(100, Severity::value('debug'));
        $this->assertSame(200, Severity::value('info'));
        $this->assertSame(250, Severity::value('notice'));
        $this->assertSame(300, Severity::value('warning'));
        $this->assertSame(400, Severity::value('error'));
        $this->assertSame(500, Severity::value('critical'));
        $this->assertSame(550, Severity::value('alert'));
        $this->assertSame(600, Severity::value('emergency'));
    }

    /**
     * Level names are case-insensitive, unknown names score zero.
     *
     * @return void
     */
    public function testValuesAreCaseInsensitive(): void
    {
        $this->assertSame(400, Severity::value('ERROR'));
        $this->assertSame(0, Severity::value('bogus'));
        $this->assertFalse(Severity::known('bogus'));
        $this->assertTrue(Severity::known('Warning'));
    }

    /**
     * Minimum-level comparison is inclusive.
     *
     * @return void
     */
    public function testMeets(): void
    {
        $this->assertTrue(Severity::meets('error', 'error'));
        $this->assertTrue(Severity::meets('critical', 'error'));
        $this->assertTrue(Severity::meets('ERROR', 'warning'));
        $this->assertFalse(Severity::meets('info', 'error'));
        $this->assertFalse(Severity::meets('bogus', 'debug'));
    }
}
