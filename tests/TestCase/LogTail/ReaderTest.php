<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\Reader;

/**
 * Tests for the follow reader (deterministic drain paths).
 */
class ReaderTest extends TestCase
{
    /**
     * Temporary file.
     *
     * @var string
     */
    protected string $file = '';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_reader_test_' . uniqid() . '.tail';
        touch($this->file);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    /**
     * Backfill mode drains complete lines; partial tails wait for completion.
     *
     * @return void
     */
    public function testFromStartDrainsCompleteLines(): void
    {
        file_put_contents($this->file, "one\ntwo\npartial");

        $seen = [];
        $delivered = (new Reader($this->file, true))->follow(
            static function (string $line) use (&$seen): bool {
                $seen[] = $line;

                return true;
            },
            0,
        );

        $this->assertSame(2, $delivered);
        $this->assertSame(['one', 'two'], $seen);
    }

    /**
     * Default mode starts at the end, like `tail -F`.
     *
     * @return void
     */
    public function testStartsAtEndByDefault(): void
    {
        file_put_contents($this->file, "old\n");

        $seen = [];
        $delivered = (new Reader($this->file))->follow(
            static function (string $line) use (&$seen): bool {
                $seen[] = $line;

                return true;
            },
            0,
        );

        $this->assertSame(0, $delivered);
        $this->assertSame([], $seen);
    }

    /**
     * Max lines and early-stop callbacks bound the run.
     *
     * @return void
     */
    public function testLimits(): void
    {
        file_put_contents($this->file, "a\nb\nc\n");

        $seen = [];
        $delivered = (new Reader($this->file, true))->follow(
            static function (string $line) use (&$seen): bool {
                $seen[] = $line;

                return true;
            },
            0,
            2,
        );

        $this->assertSame(2, $delivered);
        $this->assertSame(['a', 'b'], $seen);

        $stopped = (new Reader($this->file, true))->follow(static fn(string $line): bool => true, 30, 0, static fn(): bool => true);
        $this->assertSame(0, $stopped);
    }

    /**
     * Returning false from the callback stops after the current line.
     *
     * @return void
     */
    public function testCallbackStop(): void
    {
        file_put_contents($this->file, "a\nb\n");

        $seen = [];
        $delivered = (new Reader($this->file, true))->follow(
            static function (string $line) use (&$seen): bool {
                $seen[] = $line;

                return false;
            },
            0,
        );

        $this->assertSame(1, $delivered);
        $this->assertSame(['a'], $seen);
    }

    /**
     * A transient read failure (e.g. Windows lock contention) is retried, never surfaced.
     *
     * @return void
     */
    public function testTransientReadFailureIsRetried(): void
    {
        file_put_contents($this->file, "a\nb\n");

        $reader = new class ($this->file, true) extends Reader {
            /**
             * Fail the first read only.
             *
             * @var bool
             */
            public bool $failOnce = true;

            /**
             * @inheritDoc
             */
            protected function read(mixed $handle): ?string
            {
                if ($this->failOnce) {
                    $this->failOnce = false;

                    return null;
                }

                return parent::read($handle);
            }
        };

        $seen = [];
        $delivered = $reader->follow(
            static function (string $line) use (&$seen): bool {
                $seen[] = $line;

                return true;
            },
            2,
        );

        $this->assertSame(2, $delivered);
        $this->assertSame(['a', 'b'], $seen);
    }
}
