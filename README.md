# CakePHP DevConsole Plugin

The **DevConsole** plugin runs all local development processes for CakePHP applications from a single terminal window using the `bin/cake dev` console command.

DevConsole concurrently starts the CakePHP development server, the Vite asset watcher, and any custom processes such as queue workers. Each process runs in its own labeled, color-coded tab with searchable, scrollable output, and crashed processes are restarted automatically.

The plugin uses the `@crustum/multiplex` tabbed TUI under the hood, with a `concurrently` fallback on Windows. A process registry with userland, vendor, and default priorities controls which processes run, and companion `dev list` and `dev stop` commands inspect registrations and stop a running session.

The plugin integrates seamlessly with CakePHP's console system and is configured through a `config/dev_console.php` file installed via the manifest system.

## Requirements

* PHP 8.2+

See [Versions.md](docs/Versions.md) for the supported CakePHP versions.

## Installation

You can install this plugin into your CakePHP application using [composer](https://getcomposer.org):

```
composer require crustum/cakephp-dev-console
```

Load the plugin:

```
bin/cake plugin load Crustum/DevConsole
```

## Documentation

For documentation, as well as tutorials, see the [docs](docs/index.md) directory of this repository.

## License

Licensed under the [MIT](http://www.opensource.org/licenses/mit-license.php) License. Redistributions of the source code included in this repository must retain the copyright notice found in each file.
