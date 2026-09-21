<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\ScopeFilter;

/**
 * Tests for the CakePHP scope filtering (Speculum LogWatcher semantics).
 */
class ScopeFilterTest extends TestCase
{
    /**
     * Null configuration allows everything.
     *
     * @return void
     */
    public function testNullAllowsAll(): void
    {
        $this->assertTrue(ScopeFilter::allows(['scope' => []]));
        $this->assertTrue(ScopeFilter::allows(['scope' => ['payments']]));
        $this->assertTrue(ScopeFilter::allows([]));
    }

    /**
     * Empty configuration allows only unscoped messages.
     *
     * @return void
     */
    public function testEmptyAllowsOnlyUnscoped(): void
    {
        $this->assertTrue(ScopeFilter::allows(['scope' => []], []));
        $this->assertFalse(ScopeFilter::allows(['scope' => ['payments']], []));
    }

    /**
     * Listed scopes intersect; unscoped inclusion is configurable.
     *
     * @return void
     */
    public function testConfiguredScopes(): void
    {
        $this->assertTrue(ScopeFilter::allows(['scope' => ['payments']], ['payments', 'orders']));
        $this->assertFalse(ScopeFilter::allows(['scope' => ['audit']], ['payments']));
        $this->assertTrue(ScopeFilter::allows(['scope' => []], ['payments']));
        $this->assertFalse(ScopeFilter::allows(['scope' => []], ['payments'], false));
    }

    /**
     * Scope normalization covers CakePHP's string, list and empty forms.
     *
     * @return void
     */
    public function testNormalize(): void
    {
        $this->assertSame([], ScopeFilter::normalize(null));
        $this->assertSame([], ScopeFilter::normalize(''));
        $this->assertSame([], ScopeFilter::normalize([]));
        $this->assertSame(['payments'], ScopeFilter::normalize('payments'));
        $this->assertSame(['a', 'b'], ScopeFilter::normalize(['a', '', null, 'b']));
    }
}
