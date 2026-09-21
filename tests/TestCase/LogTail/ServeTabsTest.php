<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\ServeTabs;

/**
 * Tests for the explicit tab configuration.
 */
class ServeTabsTest extends TestCase
{
    /**
     * Valid entries normalize; empty titles, empty definitions and unknown levels drop out.
     *
     * @return void
     */
    public function testNormalize(): void
    {
        $tabs = ServeTabs::normalize([
            'payments' => ['scopes' => ['payments']],
            'errors' => ['files' => ['error.log'], 'level' => 'Warning'],
            'empty' => [],
            '' => ['scopes' => ['x']],
            'bogus' => ['level' => 'nope'],
            'junk' => 'payments',
        ]);

        $this->assertSame([
            ['title' => 'payments', 'scopes' => ['payments']],
            ['title' => 'errors', 'files' => ['error.log'], 'level' => 'warning'],
        ], $tabs);
    }

    /**
     * Non-array config normalizes to no tabs (host falls back to derived/source tabs).
     *
     * @return void
     */
    public function testNonArray(): void
    {
        $this->assertSame([], ServeTabs::normalize(null));
        $this->assertSame([], ServeTabs::normalize('payments'));
    }
}
