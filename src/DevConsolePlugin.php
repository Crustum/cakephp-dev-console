<?php
declare(strict_types=1);

namespace Crustum\DevConsole;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Crustum\DevConsole\Process\Processes;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Override;

/**
 * Plugin for Crustum/DevConsole
 *
 * @uses \Crustum\PluginManifest\Manifest\ManifestTrait
 */
class DevConsolePlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    /**
     * @inheritDoc
     */
    #[Override]
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        if (!Configure::check('DevConsole')) {
            if (file_exists(CONFIG . 'dev_console.php')) {
                Configure::load('dev_console', 'default');
            } elseif (file_exists($this->getConfigPath() . 'dev_console.php')) {
                Configure::load('Crustum/DevConsole.dev_console', 'default', false);
            }
        }

        if ((bool)(Configure::read('DevConsole.registerDefaults') ?? true)) {
            Processes::registerDefaults();
        }

        $configured = Configure::read('DevConsole.commands');

        if (is_array($configured)) {
            Processes::registerMany($configured);
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands = parent::console($commands);

        $commands->add('dev', Command\DevCommand::class);
        $commands->add('dev list', Command\DevListCommand::class);
        $commands->add('dev stop', Command\DevStopCommand::class);

        return $commands;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function services(ContainerInterface $container): void
    {
    }

    /**
     * Plugin install assets via crustum/plugin-manifest.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__);

        return array_merge(
            static::manifestConfig(
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'dev_console.php',
                CONFIG . 'dev_console.php',
                false,
            ),
            static::manifestBootstrapAppend(
                "if (file_exists(CONFIG . 'dev_console.php')) {\n    Configure::load('dev_console', 'default');\n}",
                '// DevConsole Plugin Configuration',
            ),
            static::manifestStarRepo('Crustum/DevConsole'),
        );
    }
}
