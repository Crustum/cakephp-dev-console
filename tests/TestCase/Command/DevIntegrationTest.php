<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Process\Processes;
use TestApp\Application;

/**
 * Integration tests for the dev commands through the full console stack.
 *
 * Uses the `exec()` pattern (see Broadcasting's ChannelCommandTest):
 * commands run via the TestApp with the plugin loaded instead of calling
 * `execute()` directly, so option parsing and plugin wiring are covered.
 */
class DevIntegrationTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace('TestApp');
        $this->configApplication(Application::class, [CONFIG]);

        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }

        Processes::reset();
        Processes::register('php bin/cake.php server', 'server');
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        Processes::reset();

        $file = Processes::pidFile();

        if (is_file($file)) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * `dev list` lists the registered processes end to end.
     *
     * @return void
     */
    public function testDevListViaExec(): void
    {
        $this->exec('dev list');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertOutputContains('server');
        $this->assertOutputContains('php bin/cake.php server');
    }

    /**
     * `dev stop` without a pid file reports nothing running.
     *
     * @return void
     */
    public function testDevStopViaExecWithoutPidFile(): void
    {
        $this->exec('dev stop');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertOutputContains('No dev processes are running.');
    }

    /**
     * `dev --dry-run` prints the runner command without executing it.
     *
     * @return void
     */
    public function testDevDryRunViaExec(): void
    {
        $this->exec('dev --dry-run --runner concurrently');

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertOutputContains('server');
    }
}
