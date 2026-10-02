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

Find the translations your Laravel app is missing, and clean up the ones it no longer uses.

The package looks through your PHP files and Blade views for translation calls like `__()`, `trans()`, `trans_choice()`, `@lang` and `Lang::get()`, and tells you:

- which keys are **missing** a translation in one of your locales;
- which translations in your `lang` folder are **unused**, so you can remove them.

## Installation

Install the package as a dev dependency:

```bash
composer require --dev simonecerruti/laravel-translation-audit
```

That's it, no setup needed. If you want to tweak the defaults, publish the config file:

```bash
php artisan vendor:publish --tag="laravel-translation-audit-config"
```

## Finding Missing Translations

Run the audit:

```bash
php artisan translation:audit
```

You get the keys with a missing translation, grouped by the file that uses them:

```text
  app/Http/Controllers/Auth/LoginController.php
    IT      auth.failed
    EN, IT  Welcome back! Please sign in to continue with your
            account.

  resources/views/welcome.blade.php
    IT      messages.welcome

Found 3 keys with missing translations in 2 files.
```

Your locales are detected from the `lang` folder, or you can list them in the `supported_locales` config.

## Finding Unused Translations

Add `--unused` to also list the translations no file uses:

```bash
php artisan translation:audit --unused
```

To check them on every run, set `audit_unused` to `true` in the config file.

Laravel's own translation files (`auth.php`, `pagination.php`, `passwords.php` and `validation.php`) are skipped, since the framework uses them. You can change this list with the `unused_ignore_paths` config.

## Removing Unused Translations

Once you know which translations are unused, you can remove them from your `lang` files in one go. Start with a dry run to see what would be removed, without touching any file:

```bash
php artisan translation:purge-unused --dry-run
```

```text
  IT
    lang/it.json
      Goodbye
        Arrivederci
    lang/it/messages.php
      messages.old
        Vecchio

2 unused translations would be purged.
```

When you're happy with the list, run it for real:

```bash
php artisan translation:purge-unused
```

> [!WARNING]
> PHP translation files are rewritten from scratch, so their comments and custom formatting are lost. Commit your changes first, so you can review the diff and roll back if needed.

The same files and keys skipped by the unused audit are never removed.

## Using It in CI

The audit fails (non-zero exit code) when it finds missing translations, or unused ones when you ask for them, so it can block a pull request. The package ships a ready-made GitHub workflow that runs it on every push to `main` and on every pull request:

```bash
php artisan vendor:publish --tag="laravel-translation-audit-workflow"
```

## Output Formats

Pick how the result is printed with `--display-format`, or set it once with the `display_format` config:

- `list` (default): the keys grouped by file, like the examples above.
- `table`: one row per key.
- `json`: a single line of JSON, handy for scripts.

```bash
php artisan translation:audit --display-format=json
```

```json
{"missing":{"app/Http/Controllers/HomeController.php":{"auth.failed":["it"],"messages.welcome":["en","it"]}}}
```

Only the result goes to the standard output, so `php artisan translation:audit --display-format=json | jq` works as expected. The progress bar, the summary and the messages are printed separately and stay in your terminal.

Use `--no-progress` and `--no-summary` to hide the progress bar and the summary line.

## Running It from an AI Agent

When the audit runs inside an AI agent, like Claude Code, Codex, Cursor, Gemini CLI or GitHub Copilot, it detects it and prints only the JSON result, so the agent can read it easily. You can ask for the same output yourself with `--for-agent`:

```bash
php artisan translation:audit --for-agent
```

## Saving the Result

Add `--save` to also write the result to a JSON file:

```bash
php artisan translation:audit --save
```

By default the file goes to `storage/app/private/translation-audits`. You can change where it's saved and how it's named with `--save-path` and `--save-name`, or the matching config values. The name can include the date and a random string:

```bash
php artisan translation:audit --save --save-path=/tmp/audits --save-name="audit-{now:Y-m-d}-{random:8}"
```

## Configuration

Most options have a matching config value, and the option always wins for a single run. A few settings are only available in the config file:

| Config | What it does |
| --- | --- |
| `scan_paths` | The files to scan, as glob patterns. By default `app/**/*.php` and `resources/views/**/*.blade.php`. |
| `ignore_paths` | The files to skip, even when they match `scan_paths`. |
| `supported_locales` | Your app's locales. `['auto']` detects them from the `lang` folder. |
| `ignore_locales` | The locales to leave out, e.g. `['en']` when your keys are the English text. |
| `ignore_keys` | The keys to leave out, for every locale or only for some. |
| `unused_ignore_paths` | The translation files never reported as unused, nor removed. |

To ignore a key everywhere, list it on its own. To ignore it only for some locales, map it to them:

```php
'ignore_keys' => [
    'Hello',          // ignored for every locale
    'Hi' => ['en'],   // ignored only for English
],
```

Symbolic links aren't followed by default. Pass `--follow-links`, or set `always_follow_links` to `true`, to scan the files behind them too. `vendor` and `node_modules` are never followed.

The comments in the published config file describe every setting in detail.

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
