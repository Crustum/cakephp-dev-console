# CakePHP DevConsole Plugin

- [Introduction](#introduction)
- [Installation](#installation)
- [The Dev Command](#the-dev-command)
    - [Customizing Dev Processes](#customizing-dev-processes)
    - [Queue Worker](#queue-worker)
    - [Restarting Failed Processes](#restarting-failed-processes)
    - [Filtering Dev Processes](#filtering-dev-processes)
    - [Display Modes](#display-modes)
    - [Process Runners](#process-runners)
    - [Listing Processes](#listing-processes)
    - [Stopping Processes](#stopping-processes)
    - [Configuration Reference](#configuration-reference)
- [The Logs Commands](#the-logs-commands)
    - [Logs Tail](#logs-tail)
    - [Logs TUI](#logs-tui)
    - [Custom Log Tabs](#custom-log-tabs)
    - [Logs Serve](#logs-serve)

<a name="introduction"></a>
## Introduction

The `dev` console command starts everything needed for local development in a single terminal window. By default, it concurrently runs the CakePHP development server and the Vite asset watcher (when your application has a `package.json` file):

```bash
bin/cake dev
```

Under the hood, the `dev` command uses the `@crustum/multiplex` npm package to manage the processes, giving each process its own tab with searchable, scrollable output. Each process is labeled and color-coded so you can easily distinguish between them. If a process crashes, it will be restarted automatically.

> [!NOTE]
> The `dev` command requires Node.js for the multiplex runner. Multiplex supports Windows (`win32` since `0.4.4`, Windows Terminal recommended); `concurrently` remains available as a merged-output alternative via `--runner=concurrently`.

<a name="installation"></a>
## Installation

### Installing the Plugin

Install via Composer:

```bash
composer require crustum/cakephp-dev-console
```

> [!NOTE]
> This plugin should be registered in your `config/plugins.php` file.

```bash
bin/cake plugin load Crustum/DevConsole
```

> [!TIP]
> **After the plugin registers itself**, it's recommended to install the configuration with the manifest system:

```bash
bin/cake manifest install --plugin Crustum/DevConsole
```

The DevConsole plugin will create the `config/dev_console.php` configuration file where you may customize the multiplex title, runner, display mode, and extra processes. Additionally, it will append the loading of the `config/dev_console.php` file to the `config/bootstrap.php` file.

Alternatively, you can load the plugin in your `Application.php`:

```php
// In src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Crustum/DevConsole');
}
```

<a name="the-dev-command"></a>
## The Dev Command

The `dev` command starts all registered development processes at once:

```bash
bin/cake dev
```

The default processes are:

| Name | Command |
| --- | --- |
| `server` | `php bin/cake.php server` |
| `vite` | `<package-manager> run dev` (only when `package.json` exists) |

> [!NOTE]
> The `vite` process automatically detects your Node package manager (npm, pnpm, Yarn, or Bun) and uses the appropriate run command.

<a name="customizing-dev-processes"></a>
### Customizing Dev Processes

You may customize the processes that the `dev` command runs by using the `Processes` class, typically within the `bootstrap` method of your application's `Application` class, after the plugin is loaded. The `register` method accepts a command string and an optional name:

```php
use Crustum\DevConsole\Process\Processes;

// In src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Crustum/DevConsole');

    Processes::register('some-command --flag', 'my-process');
}
```

When registering a CakePHP command, you may use the `cake` method which automatically prefixes the command with `php bin/cake.php`:

```php
Processes::cake('queue worker', 'queue');
```

Likewise, the `node` method prefixes the command with your detected package manager's run command (e.g. `npm run`), and the `nodeExec` method prefixes the command with the package manager's exec command (e.g. `npx`):

```php
Processes::node('storybook', 'storybook');

Processes::nodeExec('tailwindcss -i resources/css/app.css -o public/css/app.css --watch', 'tailwind');
```

If you register a process with the same name as a default process, your process will replace the default. For example, you may customize the server process to use a different port:

```php
Processes::cake('server -p 9000', 'server');
```

You may also customize the color of a process label in your terminal. The available color methods are `blue`, `purple`, `pink`, `orange`, `green`, and `yellow`. You may also pass a custom hex color to the `color` method:

```php
Processes::register('my-command', 'my-process')->green();

Processes::register('my-command', 'my-process')->color('#ff6347');
```

Extra processes may also be declared in the `config/dev_console.php` configuration file. Entries register with userland priority, so they override same-named defaults:

```php
'commands' => [
    ['command' => 'php bin/cake.php queue worker', 'name' => 'queue'],
    ['command' => 'npm run watch', 'name' => 'assets', 'color' => '#86efac'],
],
```

<a name="queue-worker"></a>
#### Queue Worker

A queue worker fits the `dev` command like any other process. Register it alongside the server and Vite watcher:

```php
use Crustum\DevConsole\Process\Processes;

Processes::cake('queue worker', 'queue')->purple();
```

Or declare it in `config/dev_console.php`:

```php
'commands' => [
    ['command' => 'php bin/cake.php queue worker', 'name' => 'queue', 'color' => '#c4b5fd'],
],
```

Then run everything together and inspect the worker tab:

```bash
bin/cake dev
bin/cake dev list --filter=queue
```

<a name="restarting-failed-processes"></a>
#### Restarting Failed Processes

If a process crashes, it will be restarted automatically. You may disable this behavior for a single run using the `--no-restart` option:

```bash
bin/cake dev --no-restart
```

Or, you may disable it for your entire application using the `disableAutoRestart` method:

```php
Processes::disableAutoRestart();
```

<a name="filtering-dev-processes"></a>
### Filtering Dev Processes

You may instruct the `dev` command to only run specific processes when it is invoked using the `only` method. Similarly, you may exclude specific processes using the `except` method:

```php
// Only run the server and vite processes...
Processes::only('server', 'vite');

// Run all processes except the queue worker...
Processes::except('queue');
```

You may control the tab order with the `order` method:

```php
Processes::order(['server', 'queue', 'vite']);
```

You may exclude commands registered by vendor packages or the plugin's default commands using the `withoutVendorCommands` and `withoutDefaultCommands` methods:

```php
Processes::withoutVendorCommands();

Processes::withoutDefaultCommands();
```

<a name="display-modes"></a>
### Display Modes

The multiplex runner supports three display modes, selected per run with a flag or application-wide via configuration:

```bash
bin/cake dev --tabs
bin/cake dev --stream
bin/cake dev --inline
```

```php
// Tabs (default), stream, or inline...
Processes::tabs();
Processes::stream();
Processes::inline();
```

The `dev` command also accepts `--timestamps` to prefix output lines, `--json` for machine-readable output, and `--buffer-size` / `--stream-buffer-size` to tune output buffering. Use `--dry-run` to print the runner invocation without starting any process.

Timestamps, restart behavior, mode, and buffer sizes may also be set in code:

```php
Processes::withTimestamps();
Processes::bufferSize(1000);
Processes::streamBufferSize(2000);
```

<a name="process-runners"></a>
### Process Runners

Two runners are available: `multiplex` (tabbed TUI) and `concurrently` (merged output). The runner defaults to multiplex, except on Windows where it defaults to concurrently. You may override it per run or via configuration:

```bash
bin/cake dev --runner=concurrently
```

```php
// In config/dev_console.php
'runner' => 'auto', // 'auto', 'multiplex', or 'concurrently'
```

The multiplex runner is resolved from the published `@crustum/multiplex` npm package via your detected Node package manager. To use a local checkout instead, point `multiplexPath` at the directory containing the checkout or at its `cli.js` file:

```php
// In config/dev_console.php
'multiplexPath' => '/path/to/multiplex',
```

<a name="listing-processes"></a>
### Listing Processes

To see all registered dev processes without starting them, use the `dev list` command:

```bash
bin/cake dev list
```

Each row shows the command, name, color, registration source, and priority. The listing may be filtered by name or command substring, restricted with the vendor flags, or rendered as JSON:

```bash
bin/cake dev list --filter=server
bin/cake dev list --except-vendor
bin/cake dev list --only-vendor
bin/cake dev list --json
```

<a name="stopping-processes"></a>
### Stopping Processes

The `dev` command records its supervisor PID while running. To stop a running session from another terminal, use the `dev stop` command:

```bash
bin/cake dev stop
bin/cake dev stop --timeout=10
```

<a name="the-logs-commands"></a>
## The Logs Commands

Three commands cover application logs. `logs tail` streams entries inline (PHP-only, works everywhere), `logs tui` browses them in a full terminal UI, and `logs serve` is the collector the TUI spawns under the hood:

```bash
bin/cake logs tail --level=warning --scope=payments
bin/cake logs tui
```

<a name="logs-tail"></a>
### Logs Tail

The `logs tail` command streams log entries to the terminal as they are written:

```bash
bin/cake logs tail
```

| Option | Description |
| --- | --- |
| `--filter=<value>` | Only show entries containing the given value |
| `--message=<value>` | Only show entries with the given message |
| `--level=<level>` | Only show entries at or above the given minimum level (`debug`..`emergency`) |
| `--scope=<scopes>` | Only show entries with the given comma-separated scopes |
| `--timeout=<seconds>` | Stop after this many seconds (default: `3600`) |
| `--lines=<n>` | Exit after printing this many lines (`0` for unlimited) |
| `-v` | Show more details: dates, untruncated messages, exception traces |

```bash
bin/cake logs tail --level=error --lines=50
bin/cake logs tail --scope=payments,orders --message=timeout -v
```

> [!NOTE]
> `logs tail` is pure PHP with no extra runtime requirements, so it works on every platform including Windows. Press `Ctrl+C` to exit.

<a name="logs-tui"></a>
### Logs TUI

The `logs tui` command opens the logs in an interactive terminal UI: a tab sidebar on the left, the log pane on the right, and a status footer:

```bash
bin/cake logs tui
bin/cake logs tui --tail=500 --sources=cake_live,cake_file
```

| Option | Description |
| --- | --- |
| `--tail=<n>` | Backfill lines per source before going live (default: `200`) |
| `--sources=<list>` | Comma-separated sources (default: `cake_live,cake_file`) |
| `--dry-run` | Print the TUI command without executing it |

> [!NOTE]
> The TUI needs Bun >= 1.3 or Node >= 26.4. Without a new-enough runtime it fails fast with a message pointing at `bin/cake logs tail`, which is PHP-only and always works.

Inside the TUI you may:

- Switch tabs with `1`-`9` (tabs mirror your `Log` engine configs — one per engine — plus the raw streams).
- Toggle the `lines` / `cards` view with `v`, and the sidebar with `b`.
- Filter with `/` (global) or `f` (current tab), and set a minimum severity with `l` (picker) or `L` (step).
- Open the selected event with `Enter` (or click a focused row) for the full message, origin, and exception details.
- Toggle follow with `s`, switch dark / light theme with `t`, copy a row with `Ctrl+C` (press again to quit), and press `?` for the full key list.

Which TUI binary runs is resolved like the `dev` runners: the `logsTuiPath` configuration value (a checkout directory or its `cli.js`) wins, otherwise the published `@crustum/log-tui` npm package is used via your detected Node package manager.

<a name="custom-log-tabs"></a>
#### Custom Log Tabs

By default the TUI derives one tab per `Log` engine configuration, so your sidebar already matches how the application routes logs. To define your own tabs instead, set the `DevConsole.logs.tabs` configuration value — each tab matches by scopes, files, or a minimum level:

```php
// In config/dev_console.php
'logs' => [
    'tabs' => [
        'payments' => ['scopes' => ['payments']],
        'errors' => ['files' => ['error.log'], 'level' => 'warning'],
    ],
],
```

Custom tabs replace the derived defaults entirely (`All` always stays first). Entries with an empty title, no scopes/files/level, or an unknown level are skipped, so a typo never breaks the collector handshake.

<a name="logs-serve"></a>
### Logs Serve

The `logs serve` command streams logs as NDJSON for the TUI host. You rarely run it directly — `logs tui` spawns and supervises it — but it is handy for debugging the stream itself:

```bash
bin/cake logs serve --timeout=5 | head
```

| Option | Description |
| --- | --- |
| `--tail=<n>` | Backfill lines per file source, `0` for live only (default: `200`) |
| `--sources=<list>` | Comma-separated sources (default: `cake_live,cake_file`) |
| `--timeout=<seconds>` | Stop after this many seconds (`0` for unlimited) |

The collector is stateless by design: it taps the live logging pipeline and backfills `LOGS/*.log` files straight to stdout. It never filters, counts, or buffers — the TUI host owns all of that.

<a name="configuration-reference"></a>
### Configuration Reference

All settings live in the `config/dev_console.php` configuration file and are read as `Configure::read('DevConsole.*')`:

| Key | Description |
|-----|-------------|
| `multiplexPath` | Directory containing a multiplex checkout, or the `cli.js` file itself. Null uses the published `@crustum/multiplex` npm package. |
| `logsTuiPath` | Directory containing a log-tui checkout, or the `cli.js` file itself. Defaults to the checkout bundled with this plugin (`workspace/log-tui`); when that has no built `dist/cli.js`, or when set to null, the published `@crustum/log-tui` npm package is used. |
| `appName` | Name shown in the multiplex title bar. Defaults to the `APP_NAME` environment variable, falling back to the app folder name. |
| `forceRegister` | Test-only: force process registration outside a console SAPI. |
| `registerDefaults` | Register the default processes (cake server, vite when `package.json` exists) on plugin bootstrap. Set `false` to register everything manually. |
| `runner` | Process runner: `auto` (multiplex, except concurrently on Windows), `multiplex`, or `concurrently`. The `--runner` CLI option overrides this. |
| `mode` | Display mode: `tabs` (default), `stream`, or `inline`. CLI flags and the `Processes` static API override this. |
| `commands` | Extra processes, registered with userland priority. Each entry: `command` (required), `name`, `color`. |
