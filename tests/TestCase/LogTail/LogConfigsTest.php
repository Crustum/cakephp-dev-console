<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\LogConfigs;
use Crustum\DevConsole\LogTail\TailEngine;

/**
 * Tests for the Log engine description sent to the TUI host.
 */
class LogConfigsTest extends TestCase
{
    /**
     * Engines registered during the test (removed in tearDown).
     *
     * @var list<string>
     */
    protected array $added = [];

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        foreach ($this->added as $name) {
            if (Log::getConfig($name)) {
                Log::drop($name);
            }
        }

        $this->added = [];

        parent::tearDown();
    }

    /**
     * Register a throwaway array engine config.
     *
     * @param string $name Engine name.
     * @param array<string, mixed> $config Config.
     * @return void
     */
    protected function engine(string $name, array $config): void
    {
        if (Log::getConfig($name)) {
            Log::drop($name);
        }

        Log::setConfig($name, $config + ['className' => 'Array']);
        $this->added[] = $name;
    }

    /**
     * Engines describe with sanitized keys; the tail engine is excluded; urls never leak.
     *
     * @return void
     */
    public function testDescribe(): void
    {
        $this->engine('tui_test_email', [
            'file' => 'email',
            'scopes' => ['email', 'cake.mailer'],
            'url' => 'file:///secret',
        ]);
        $this->engine('tui_test_error', [
            'file' => 'error',
            'levels' => ['Warning', 'ERROR'],
        ]);
        $this->engine('tui_test_all', []);
        TailEngine::register();

        $described = LogConfigs::describe();
        $byName = [];

        foreach ($described as $engine) {
            $byName[$engine['name']] = $engine;
        }

        $this->assertArrayHasKey('tui_test_email', $byName);
        $this->assertSame('email', $byName['tui_test_email']['file']);
        $this->assertSame(['email', 'cake.mailer'], $byName['tui_test_email']['scopes']);
        $this->assertNull($byName['tui_test_email']['levels']);

        $this->assertSame(['warning', 'error'], $byName['tui_test_error']['levels']);
        $this->assertNull($byName['tui_test_error']['scopes']);

        $this->assertNull($byName['tui_test_all']['file']);
        $this->assertArrayNotHasKey(TailEngine::CONFIG_KEY, $byName);

        $encoded = (string)json_encode($described);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertStringNotContainsString('className', $encoded);

        TailEngine::unregister();
    }
}
