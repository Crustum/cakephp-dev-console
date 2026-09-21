<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\CliPrinter;
use Crustum\DevConsole\LogTail\LogEntry;
use RuntimeException;

/**
 * Tests for the card printer.
 *
 * The stub buffers raw output (tags intact), so assertions cover the styling
 * contract as well as the text.
 */
class CliPrinterTest extends TestCase
{
    /**
     * Compact card shows time, badge, message and footer.
     *
     * @return void
     */
    public function testCompactCard(): void
    {
        $out = new StubConsoleOutput();
        $printer = new CliPrinter(new ConsoleIo($out, $out), '/app');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/payments';
        try {
            $printer->print(LogEntry::fromRecord('error', 'Payment failed', ['amount' => 100]));
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        }

        $text = implode("\n", $out->messages());
        $this->assertMatchesRegularExpression(
            '#<comment>┌</comment> <comment>\d{2}:\d{2}:\d{2}</comment> <error>ERROR</error>#',
            $text,
        );
        $this->assertStringContainsString('<comment>│</comment> Payment failed', $text);
        $this->assertStringContainsString('<info>GET /payments</info>', $text);
        $this->assertStringContainsString('guest', $text);
        $this->assertStringContainsString('amount: 100', $text);
    }

    /**
     * Authenticated requests.
     *
     * @return void
     */
    public function testCardWithAuth(): void
    {
        $out = new StubConsoleOutput();
        $printer = new CliPrinter(new ConsoleIo($out, $out), '/app');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/me';
        try {
            $request = (new ServerRequest([
                'environment' => ['REQUEST_METHOD' => 'GET'],
                'url' => '/me',
            ]))->withAttribute('identity', ['id' => 7, 'email' => 'a@b.c']);
            Router::setRequest($request);

            try {
                $printer->print(LogEntry::fromRecord('info', 'Hi'));
            } finally {
                Router::reload();
            }
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        }

        $text = implode("\n", $out->messages());
        $this->assertStringContainsString('Auth: 7 (a@b.c)', $text);
    }

    /**
     * Verbose card shows the full date and exception traces.
     *
     * @return void
     */
    public function testVerboseCardWithException(): void
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $io->level(ConsoleIo::VERBOSE);

        $printer = new CliPrinter($io, '/app');

        $printer->print(LogEntry::fromRecord('error', 'Failed', ['exception' => new RuntimeException('Boom')]));

        $text = implode("\n", $out->messages());
        $this->assertMatchesRegularExpression(
            '#<comment>┌</comment> <comment>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}</comment> <error>RuntimeException</error>#',
            $text,
        );
        $this->assertStringContainsString('<comment>│</comment> Failed', $text);
        $this->assertMatchesRegularExpression('#<comment>│</comment> <comment>1\. .*:\d+</comment>#', $text);
    }

    /**
     * Compact card hides traces and caps long messages.
     *
     * @return void
     */
    public function testCompactHidesTrace(): void
    {
        $out = new StubConsoleOutput();
        $printer = new CliPrinter(new ConsoleIo($out, $out), '/app');

        $printer->print(LogEntry::fromRecord(
            'error',
            "Line one\nLine two\nLine three\nLine four\nLine five\nLine six",
            ['exception' => new RuntimeException('Boom')],
        ));

        $text = implode("\n", $out->messages());
        $this->assertStringContainsString('<comment>│</comment> Line one', $text);
        $this->assertStringNotContainsString('Line six', $text);
        $this->assertDoesNotMatchRegularExpression('#<comment>1\. #', $text);
    }
}
