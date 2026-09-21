<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\LogTail;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\LogTail\LiveEngineIndex;

/**
 * Tests for live-event engine attribution.
 */
class LiveEngineIndexTest extends TestCase
{
    /**
     * @param list<string> $scopes
     * @return array{level: string, meta: array<string, mixed>}
     */
    protected function event(string $level, array $scopes): array
    {
        return ['level' => $level, 'meta' => ['scopes' => $scopes]];
    }

    /**
     * A scope hit stamps the engine name and the log basename.
     *
     * @return void
     */
    public function testScopedHitStampsEngineAndFile(): void
    {
        $index = new LiveEngineIndex([
            ['name' => 'queries', 'file' => 'queries', 'scopes' => ['queriesLog'], 'levels' => null],
        ]);

        $event = $index->attribute($this->event('debug', ['queriesLog', 'cake.database.queries']));

        $this->assertSame('queries', $event['meta']['engine']);
        $this->assertSame('queries.log', $event['meta']['file']);
    }

    /**
     * Scoped engines beat catch-alls; the first winner in order wins.
     *
     * @return void
     */
    public function testScopedBeatsCatchAll(): void
    {
        $index = new LiveEngineIndex([
            ['name' => 'catch', 'file' => null, 'scopes' => null, 'levels' => null],
            ['name' => 'queries', 'file' => 'queries.log', 'scopes' => ['queriesLog'], 'levels' => null],
        ]);

        $event = $index->attribute($this->event('debug', ['queriesLog']));

        $this->assertSame('queries', $event['meta']['engine']);
    }

    /**
     * Unscoped events fall back to the catch-all engine.
     *
     * @return void
     */
    public function testUnscopedFallsBackToCatchAll(): void
    {
        $index = new LiveEngineIndex([
            ['name' => 'queries', 'file' => 'queries', 'scopes' => ['queriesLog'], 'levels' => null],
            ['name' => 'app', 'file' => null, 'scopes' => null, 'levels' => null],
        ]);

        $event = $index->attribute($this->event('info', []));

        $this->assertSame('app', $event['meta']['engine']);
        $this->assertArrayNotHasKey('file', $event['meta']);
    }

    /**
     * Level allowlists gate both scoped and catch-all engines.
     *
     * @return void
     */
    public function testLevelGate(): void
    {
        $index = new LiveEngineIndex([
            ['name' => 'errors', 'file' => 'errors', 'scopes' => null, 'levels' => ['error', 'critical']],
        ]);

        $this->assertSame('errors', $index->attribute($this->event('ERROR', []))['meta']['engine']);
        $this->assertArrayNotHasKey('engine', $index->attribute($this->event('debug', []))['meta']);
    }

    /**
     * Events without a winner pass through untouched.
     *
     * @return void
     */
    public function testNoWinnerPassesThrough(): void
    {
        $index = new LiveEngineIndex([
            ['name' => 'queries', 'file' => 'queries', 'scopes' => ['queriesLog'], 'levels' => null],
        ]);

        $event = $this->event('debug', ['other']);
        $this->assertSame($event, $index->attribute($event));
        $this->assertSame($event, (new LiveEngineIndex([]))->attribute($event));
    }
}
