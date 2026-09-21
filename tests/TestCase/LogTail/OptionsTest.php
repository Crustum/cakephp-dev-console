<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\Console\Arguments;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\Options;

/**
 * Tests for the tail filter options.
 */
class OptionsTest extends TestCase
{
    /**
     * Empty options accept everything.
     *
     * @return void
     */
    public function testEmptyAcceptsAll(): void
    {
        $options = new Options(60);

        $this->assertTrue($options->accepts(LogEntry::fromRecord('debug', 'Hi', ['scope' => ['x']])));
        $this->assertSame(60, $options->timeout());
        $this->assertSame(0, $options->lines());
    }

    /**
     * Level acts as a minimum.
     *
     * @return void
     */
    public function testLevelIsMinimum(): void
    {
        $options = new Options(60, 0, 'warning');

        $this->assertTrue($options->accepts(LogEntry::fromRecord('error', 'Bad')));
        $this->assertTrue($options->accepts(LogEntry::fromRecord('WARNING', 'Careful')));
        $this->assertFalse($options->accepts(LogEntry::fromRecord('info', 'Fine')));
    }

    /**
     * Substring filters match case-insensitively.
     *
     * @return void
     */
    public function testSubstrings(): void
    {
        $options = new Options(60, 0, null, 'TIMEOUT', 'failed');

        $this->assertTrue($options->accepts(LogEntry::fromRecord('error', 'Charge FAILED after timeout')));
        $this->assertFalse($options->accepts(LogEntry::fromRecord('error', 'Charge failed fast')));
    }

    /**
     * Scope filter delegates to the scope semantics.
     *
     * @return void
     */
    public function testScopes(): void
    {
        $options = new Options(60, 0, null, null, null, ['payments']);

        $this->assertTrue($options->accepts(LogEntry::fromRecord('info', 'Hi', ['scope' => ['payments']])));
        $this->assertFalse($options->accepts(LogEntry::fromRecord('info', 'Hi', ['scope' => ['audit']])));
    }

    /**
     * `--scope` parses comma-separated lists; blanks become null.
     *
     * @return void
     */
    public function testFromArguments(): void
    {
        $args = new Arguments([], [
            'timeout' => '30',
            'lines' => '5',
            'level' => 'error',
            'filter' => null,
            'message' => '',
            'scope' => 'payments, orders',
        ], []);

        $options = Options::fromArguments($args);

        $this->assertSame(30, $options->timeout());
        $this->assertSame(5, $options->lines());
        $this->assertTrue($options->accepts(LogEntry::fromRecord('error', 'Hi', ['scope' => ['orders']])));
        $this->assertFalse($options->accepts(LogEntry::fromRecord('info', 'Hi', ['scope' => ['orders']])));
    }
}
