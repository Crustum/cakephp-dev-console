<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Process;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Process\Process;
use Crustum\DevConsole\Process\ProcessColor;

/**
 * Tests for the Process value object.
 */
class ProcessTest extends TestCase
{
    /**
     * Names derive from the first word of the command.
     *
     * @return void
     */
    public function testNameFromCommand(): void
    {
        $this->assertSame('php', Process::nameFromCommand('php bin/cake.php server'));
        $this->assertSame('vite', Process::nameFromCommand('vite'));
    }

    /**
     * Explicit names win over derived ones.
     *
     * @return void
     */
    public function testExplicitName(): void
    {
        $process = new Process('php bin/cake.php server', [], 'server');

        $this->assertSame('server', $process->name());
    }

    /**
     * Color helpers set the matching palette value fluently.
     *
     * @return void
     */
    public function testFluentColors(): void
    {
        $process = (new Process('npm run dev', [], 'vite'))->blue();

        $this->assertSame(ProcessColor::BLUE->value, $process->toArray()['color']);

        $process->yellow();

        $this->assertSame(ProcessColor::YELLOW->value, $process->toArray()['color']);
    }

    /**
     * toArray exposes every field.
     *
     * @return void
     */
    public function testToArray(): void
    {
        $process = new Process(
            'php bin/cake.php server',
            ['file' => 'src/Application.php', 'line' => 42],
            'server',
            Process::PRIORITY_DEFAULT,
        );

        $this->assertSame(
            [
                'command' => 'php bin/cake.php server',
                'name' => 'server',
                'color' => null,
                'source' => ['file' => 'src/Application.php', 'line' => 42],
                'priority' => Process::PRIORITY_DEFAULT,
            ],
            $process->toArray(),
        );
    }

    /**
     * Priority defaults to userland when not given explicitly.
     *
     * @return void
     */
    public function testToArrayDefaultsToUserlandPriority(): void
    {
        $process = new Process('php bin/cake.php server', [], 'server');

        $this->assertSame(Process::PRIORITY_USERLAND, $process->toArray()['priority']);
    }

    /**
     * Custom colors are set fluently, returning the same instance.
     *
     * @return void
     */
    public function testCustomColorIsFluent(): void
    {
        $process = new Process('php bin/cake.php server', [], 'server');
        $result = $process->color('#ff0000');

        $this->assertSame($process, $result);
        $this->assertSame('#ff0000', $process->toArray()['color']);
    }

    /**
     * Each palette helper sets its color and returns the same instance.
     *
     * @return void
     */
    public function testPaletteHelpers(): void
    {
        $expected = [
            'blue' => ProcessColor::BLUE->value,
            'purple' => ProcessColor::PURPLE->value,
            'pink' => ProcessColor::PINK->value,
            'orange' => ProcessColor::ORANGE->value,
            'green' => ProcessColor::GREEN->value,
            'yellow' => ProcessColor::YELLOW->value,
        ];

        foreach ($expected as $method => $color) {
            $process = new Process('npm run dev', [], 'vite');
            $result = $process->{$method}();

            $this->assertSame($process, $result);
            $this->assertSame($color, $process->toArray()['color']);
        }
    }
}
