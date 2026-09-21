<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\LogEntry;
use Crustum\DevConsole\LogTail\Parser;

/**
 * Tests for the session-line parser.
 */
class ParserTest extends TestCase
{
    /**
     * Well-formed lines decode; blanks and garbage return null.
     *
     * @return void
     */
    public function testParse(): void
    {
        $parser = new Parser();
        $json = LogEntry::fromRecord('info', 'Hi')->toJson();

        $entry = $parser->parse($json . "\n");
        $this->assertNotNull($entry);
        $this->assertSame('Hi', $entry->message());

        $this->assertNull($parser->parse(''));
        $this->assertNull($parser->parse('   '));
        $this->assertNull($parser->parse('{broken'));
        $this->assertNull($parser->parse('{"level":"info"}'));
    }
}
