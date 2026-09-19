<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use Override;

/**
 * Test application for Crustum/DevConsole plugin tests.
 */
class Application extends BaseApplication
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function bootstrap(): void
    {
        parent::bootstrap();

        // The plugin is pre-loaded by tests/bootstrap.php on the shared
        // plugin collection; only add it when running standalone.
        if (!$this->getPlugins()->has('Crustum/DevConsole')) {
            $this->addPlugin('Crustum/DevConsole', [
                'bootstrap' => true,
                'routes' => true,
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function routes(RouteBuilder $routes): void
    {
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }
}
