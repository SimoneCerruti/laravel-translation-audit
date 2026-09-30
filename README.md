<div align="center">
    <h1>Laravel Translation Audit</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/simonecerruti/laravel-translation-audit"><img src="https://img.shields.io/packagist/v/simonecerruti/laravel-translation-audit.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/simonecerruti/laravel-translation-audit"><img src="https://img.shields.io/packagist/php-v/simonecerruti/laravel-translation-audit.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/simonecerruti/laravel-translation-audit"><img src="https://badge.laravel.cloud/badge/simonecerruti/laravel-translation-audit?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/simonecerruti/laravel-translation-audit/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/simonecerruti/laravel-translation-audit/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/simonecerruti/laravel-translation-audit"><img src="https://img.shields.io/packagist/dt/simonecerruti/laravel-translation-audit.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Audit your app for missing or unused translations.

Laravel Translation Audit scans your PHP files and Blade views for translation calls such as `__()`, `trans()`, `trans_choice()`, `@lang`, and `Lang::get()`. It then checks that every key it finds has a translation in each of your app's locales. The locales are detected automatically from your `lang` folder, or you can list them in the config file.

## Installation

The package is a development tool, so install it as a dev dependency via Composer:

```bash
composer require --dev simonecerruti/laravel-translation-audit
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-translation-audit"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-translation-audit-config"
```

### Publishing the GitHub Workflow

```bash
php artisan vendor:publish --tag="laravel-translation-audit-workflow"
```

The workflow is published to `.github/workflows/audit-translations.yml` and runs the audit on every push to `main` and on every pull request.

## Usage

```bash
php artisan translation:audit
```

The command exits with a non-zero status code when it finds missing translations, so it can be used to fail a CI pipeline.

### Following Symbolic Links

Symbolic links are not followed by default. Pass `--follow-links` to scan the files behind them as well:

```bash
php artisan translation:audit --follow-links
```

To always follow them, set `always_follow_links` to `true` in the config file. The option overrides the config for a single run, so `--follow-links=false` skips the links even when the config enables them.

The links matching the `ignore_links` glob patterns in the config file, `vendor` and `node_modules` by default, are never followed.

### Saving the Audit Result

Pass `--save` to write the audit result to a file, or set `always_save` to `true` in the config file to save it on every run:

```bash
php artisan translation:audit --save
```

The result is saved even when no translation is missing, and the command prints the path of the saved file. By default it lands in `storage/app/private/translation-audits`, in a file named like `translation-audit-30_Sep_2026_18_30-aB3dE9fG.json`.

The saved JSON lists, for each scanned file, the keys with missing translations and the locales they are missing in. It is an empty array when nothing is missing:

```json
{
    "app/Http/Controllers/HomeController.php": {
        "messages.welcome": ["it", "fr"]
    }
}
```

Each of these options overrides the matching config value for a single run:

| Option | Config | Default | Description |
| --- | --- | --- | --- |
| `--save` | `always_save` | `false` | Whether to save the audit result. Accepts `true` or `false`, and means `true` when passed without a value. |
| `--save-format` | `save_format` | `json` | The format of the saved file. Supported formats: `json`. |
| `--save-path` | `save_path` | `storage_path('app/private/translation-audits')` | The absolute path of the directory to save the file in. The directory is created if missing. |
| `--save-name` | `save_name` | `translation-audit-{now:d_M_Y_H_i}-{random:8}` | The file name, without the extension. |

The file name supports these placeholders:

- `{now:<format>}` inserts the current date in the given [PHP date format](https://www.php.net/manual/en/datetime.format.php). Slashes in the format are replaced with dashes.
- `{random:<length>}` inserts the given number of random alphanumeric characters.

```bash
php artisan translation:audit --save --save-path=/tmp/audits --save-name="audit-{now:Y-m-d}"
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Translation Audit! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Simone Cerruti](https://simonecerruti.com)
- [All Contributors](../../contributors)

## License

Laravel Translation Audit is open-sourced software licensed under the [MIT license](LICENSE.md).
