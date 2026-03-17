# Saucebase Module Installer

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](#requirements)
[![Composer](https://img.shields.io/badge/Composer-2.x-885630?logo=composer&logoColor=white)](#requirements)
[![Tests](https://github.com/saucebase-dev/module-installer/actions/workflows/php.yml/badge.svg)](https://github.com/saucebase-dev/module-installer/actions/workflows/php.yml)
[![License](https://img.shields.io/badge/License-MIT-0A7EA4)](#license)

This Composer plugin installs Sauce Base modules into the correct directory. It ships with `saucebase-dev/saucebase`, so every module that your project requires is placed where Sauce Base can find and load it. The installer stays compatible with [nWidart/laravel-modules](https://github.com/nWidart/laravel-modules) and offers a Sauce Base-focused alternative to [joshbrw/laravel-module-installer](https://github.com/joshbrw/laravel-module-installer).

## How It Works

- Registers a Composer installer for the module package type (defaults to `laravel-module`).
- Installs each module inside the Sauce Base modules directory (`Modules/` by default).
- Turns package names such as `saucebase/example-module` into StudlyCase directory names (`ExampleModule`).
- Lets the root package override the install path with the `extra.module-dir` option.

## Requirements

- PHP 8.4 or newer
- Composer 2.x
- A project based on `saucebase-dev/saucebase` (the core already requires this plugin)

## Installation

`saucebase-dev/saucebase` already requires this package. When you install the core, Composer pulls in the plugin and activates it through the `Saucebase\\ModuleInstaller\\Plugin` class, so a typical Sauce Base project needs no extra configuration.

Need the installer for a different Composer project? Require it directly:

```bash
composer require saucebase/module-installer
```

## Configuring the Module Type

The installer registers the `laravel-module` package type by default. If your application needs a different type, declare it in the root package `extra` section:

```json
{
    "extra": {
        "module-type": "saucebase-module"
    }
}
```

Any modules you install must set their `composer.json` `type` to the same value.

## Configuring the Install Location

By default, modules are installed under `Modules/` at the project root. You can change this by adding a `module-dir` key to your application `extra` section:

```json
{
    "extra": {
        "module-dir": "MyModules"
    }
}
```

With the configuration above, a module published as `saucebase/example-module` installs to `MyModules/ExampleModule`.

## Configuring Update Behaviour

By default, when you run `composer update`, the installer **merges** the new package version into
your existing module directory rather than replacing it. Your customised files are preserved; any
new files shipped in the update are added on top.

| Strategy | What happens on `composer update` |
|---|---|
| `merge` *(default)* | Existing module files are kept. New files from the package are added. Your edits always win. |
| `overwrite` | The module directory is replaced entirely with the new package contents. All local changes are lost. |

### Keeping your customisations (default)

No configuration is needed. The `merge` strategy is active by default. Running `composer update`
will add any new files the package ships without touching files you have already edited.

> **Note:** Because your local files always take precedence, bug fixes or updates to files you have
> customised will **not** be applied automatically. To pick up an upstream change to a specific
> file, replace it manually or delete it and re-run `composer update`.

### Full overwrite (CI / reproducible builds)

If you want every `composer update` to produce an exact copy of the package — discarding any
local edits — set the strategy to `overwrite` in your root `composer.json`:

```json
{
    "extra": {
        "module-update-strategy": "overwrite"
    }
}
```

This is recommended for CI pipelines and staging environments where reproducibility matters more
than preserving local changes.

### Getting a completely fresh copy of a module

With the `merge` strategy (default), delete the module directory and run `composer update`:

```bash
rm -rf Modules/ExampleModule
composer update vendor/example-module
```

The installer will perform a clean install into the now-empty path.

## Creating Sauce Base Modules

To ship a module that works with this installer:

1. Set the package `type` in the module `composer.json` to whatever your application expects (defaults to `laravel-module`).
2. Follow the Sauce Base module folder conventions; your module code should live inside the directory created by the installer.
3. Ask consumers to install the module through Composer:

   ```bash
   composer require vendor/example-module
   ```

   During installation, the plugin converts the package slug into the final directory name. For example, `vendor/example-module` becomes `Modules/ExampleModule` unless `module-dir` overrides it.

## Local Development

Clone the repository and install dependencies:

```bash
composer install
```

Useful scripts:

- `composer test` – run the PHPUnit suite (`tests/`).
- `./vendor/bin/pint` – apply Laravel Pint formatting (add `--test` for CI-style checks).
- `composer validate` – verify Composer metadata.

## License

Licensed under the [MIT License](./LICENSE).
