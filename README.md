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

You can install the package via Composer:

```bash
composer require simonecerruti/laravel-translation-audit
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
