<?php
declare(strict_types=1);

namespace Crustum\DevConsole\Test\TestCase\Service;

use Cake\TestSuite\TestCase;
use Crustum\DevConsole\Service\NodePackageManager;

/**
 * Tests for Node package manager detection.
 */
class NodePackageManagerTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    protected array $tmpDirs = [];

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_file($dir . DIRECTORY_SEPARATOR . 'placeholder')) {
                unlink($dir . DIRECTORY_SEPARATOR . 'placeholder');
            }

            foreach (['bun.lockb', 'pnpm-lock.yaml', 'yarn.lock', 'package-lock.json'] as $lock) {
                $path = $dir . DIRECTORY_SEPARATOR . $lock;

                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        $this->tmpDirs = [];

        parent::tearDown();
    }

    /**
     * Detection falls back to npm without a lock file.
     *
     * @return void
     */
    public function testDefaultsToNpm(): void
    {
        $manager = new NodePackageManager($this->tmpDir());

        $this->assertSame('npm', $manager->name());
        $this->assertSame('npx vite', $manager->getExecCommand('vite'));
        $this->assertSame('npm run dev', $manager->getRunCommand('dev'));
    }

    /**
     * Lock files select their manager in bun > pnpm > yarn order.
     *
     * @return void
     */
    public function testDetectsLockFiles(): void
    {
        $cases = [
            'yarn.lock' => ['yarn', 'yarn dlx vite', 'yarn dev'],
            'pnpm-lock.yaml' => ['pnpm', 'pnpm dlx vite', 'pnpm run dev'],
            'bun.lockb' => ['bun', 'bunx vite', 'bun run dev'],
        ];

        foreach ($cases as $lock => $expected) {
            $dir = $this->tmpDir();
            file_put_contents($dir . DIRECTORY_SEPARATOR . $lock, '');

            $manager = new NodePackageManager($dir);

            $this->assertSame($expected[0], $manager->name());
            $this->assertSame($expected[1], $manager->getExecCommand('vite'));
            $this->assertSame($expected[2], $manager->getRunCommand('dev'));
        }
    }

    /**
     * Make a fresh empty temp directory.
     *
     * @return string Directory path.
     */
    protected function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dev_console_npm_' . uniqid();
        mkdir($dir, 0777, true);

        $this->tmpDirs[] = $dir;

        return $dir;
    }
}
