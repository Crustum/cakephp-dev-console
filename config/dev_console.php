<?php
declare(strict_types=1);

/**
 * DevConsole Plugin Configuration
 *
 * Host applications copy settings into `config/dev_console.php` and load via
 * `Configure::load('dev_console')`. Keys are read as `Configure::read('DevConsole.*')`.
 */
return [
    'DevConsole' => [
        /**
         * Multiplex Runner Path
         *
         * Directory containing a multiplex checkout, or the cli.js file itself.
         * Null uses the published @crustum/multiplex npm package via the
         * detected Node package manager.
         */
        'multiplexPath' => null,
        /**
         * Application Name
         *
         * Name shown in the multiplex title bar. Defaults to the APP_NAME
         * environment variable; override with a literal to pin the title.
         * Falls back to the app folder name when empty.
         */
        'appName' => env('APP_NAME'),
        /**
         * Force Registration
         *
         * Test-only: force process registration outside a console SAPI.
         */
        'forceRegister' => false,
        /**
         * Register Defaults
         *
         * Register the default processes (cake server, vite when package.json
         * exists) on plugin bootstrap. Set false to register everything manually.
         */
        'registerDefaults' => true,
        /**
         * Process Runner
         *
         * 'auto' (multiplex, except concurrently on Windows), 'multiplex'
         * (tabbed TUI, needs Windows Terminal on Windows) or 'concurrently'
         * (merged output). CLI --runner overrides this.
         */
        'runner' => 'auto',
        /**
         * Display Mode
         *
         * 'tabs' (default), 'stream' or 'inline'. CLI --tabs/--stream/--inline
         * and the Processes static API override this.
         */
        'mode' => 'tabs',
        /**
         * Extra Processes
         *
         * Each entry needs a `command`; `name` and `color` are optional.
         * Entries register with userland priority, so they override
         * same-named defaults.
         */
        'commands' => [
            // ['command' => 'php bin/cake.php queue worker', 'name' => 'queue'],
            // ['command' => 'npm run watch', 'name' => 'assets', 'color' => '#86efac'],
        ],
    ],
];
