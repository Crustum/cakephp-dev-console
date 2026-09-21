# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-21

Logs commands: stream and browse application logs from the console.

### Added

- `logs tail` command: live PHP-only log stream with `--filter`, `--message`,
  `--level`, `--scope`, `--timeout`, `--lines`, and `-v` verbose cards.
  Works on every platform, including Windows.
- `logs tui` command: interactive terminal UI with engine tabs, `lines`/`cards`
  views, global and per-tab filters, severity picker, event inspector, follow
  toggle, and dark/light themes. Supports `--tail`, `--sources`, and `--dry-run`.
- `logs serve` collector: stateless NDJSON stream over stdio (live engine tap
  plus `LOGS/*.log` backfill/tail) spawned and supervised by `logs tui`.
- Custom log tabs via `DevConsole.logs.tabs`: match by `scopes`, `files`, or
  minimum `level`. Replaces the derived per-engine tabs; `All` always stays first.
- `logsTuiPath` configuration: checkout directory or `cli.js` wins, otherwise
  the published `@crustum/log-tui` npm package is used via the detected Node
  package manager.

## [1.0.0]

Initial release of `crustum/cakephp-dev-console` (`Crustum\DevConsole`):
run all local development processes from a single terminal window.

### Added

- `dev` command: concurrently runs the CakePHP development server and the Vite
  asset watcher (when the application has a `package.json` file). Each process
  gets its own labeled, color-coded tab with searchable, scrollable output via
  the `@crustum/multiplex` tabbed TUI (Windows supported, Windows Terminal
  recommended). Crashed processes restart automatically.
- Custom dev processes via `Processes::register()`, `Processes::cake()`,
  `Processes::node()`, and `Processes::nodeExec()`, with per-process label
  colors and `config/dev_console.php` `commands` entries (userland priority,
  overriding same-named defaults).
- Restart control: `--no-restart` per run and `Processes::disableAutoRestart()`
  application-wide.
- Process selection: `Processes::only()`, `Processes::except()`, and
  `Processes::order()`, plus `withoutVendorCommands()` and
  `withoutDefaultCommands()`.
- Display modes `tabs` (default), `stream`, and `inline` via CLI flags or the
  `Processes` API, with `--timestamps`, `--json`, `--buffer-size` /
  `--stream-buffer-size`, and `--dry-run`.
- Process runners `multiplex` (tabbed TUI) and `concurrently` (merged output),
  selectable per run with `--runner`, via `DevConsole.runner` config, or the
  `Processes` API. Multiplex resolves from the published `@crustum/multiplex`
  npm package or a local checkout via `multiplexPath`.
- `dev list` command: inspect registered processes (command, name, color,
  source, priority) with `--filter`, vendor flags, and `--json`.
- `dev stop` command: stop a running session from another terminal, with
  `--timeout`.
- Manifest installer: `bin/cake manifest install --plugin Crustum/DevConsole`
  creates `config/dev_console.php` (`multiplexPath`, `appName`,
  `forceRegister`, `registerDefaults`, `runner`, `mode`, `commands`).
