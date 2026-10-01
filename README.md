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

Laravel Translation Audit scans your PHP files and Blade views for translation calls such as `__()`, `trans()`, `trans_choice()`, `@lang`, and `Lang::get()`. It then checks that every key it finds has a translation in each of your app's locales, and optionally that every translation in your `lang` folder is used. The locales are detected automatically from your `lang` folder, or you can list them in the config file.

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

The command lists the keys with missing translations grouped by file, each preceded by the locales it is missing in:

```text
  app/Http/Controllers/Auth/LoginController.php
    IT      auth.failed
    EN, IT  Welcome back! Please sign in to continue with your
            account.

  resources/views/welcome.blade.php
    IT      messages.welcome

Found 3 keys with missing translations in 2 files.
```

While the files are scanned, a progress bar shows the file being scanned.

The result is printed on the standard output, while the progress bar, the summary, the messages and the errors are printed on the error output. Redirecting or piping the standard output, like `php artisan translation:audit > result.txt` or `php artisan translation:audit --display-format=json | jq`, captures only the result, while everything else is still displayed in the terminal. When no translation is missing, the standard output is empty, or `{"missing":{}}` with the `json` display format.

The command exits with a non-zero status code when it finds missing translations, or unused ones when they are audited, so it can be used to fail a CI pipeline.

### Choosing the Display Format

The result is printed as a list by default. Pass `--display-format`, or set `display_format` in the config file, to print it in another format. The option overrides the config for a single run.

| Format | Description |
| --- | --- |
| `list` | The keys grouped by file, each preceded by the locales it is missing in. Long keys wrap to the width of the terminal. |
| `table` | A row for each key, with a section for each file. |
| `json` | The missing locales of each key, grouped by file, under the `missing` key as JSON on a single line. It is `{"missing":{}}` when no translation is missing. |

```bash
php artisan translation:audit --display-format=json
```

```json
{"missing":{"app/Http/Controllers/HomeController.php":{"auth.failed":["it"],"messages.welcome":["en","it"]},"resources/views/welcome.blade.php":{"Welcome back!":["it"]}}}
```

Every format is followed by a summary, like `Found 3 keys with missing translations in 2 files.`

### Hiding the Progress Bar and the Summary

Pass `--no-progress` to hide the progress bar, and `--no-summary` to hide the summary:

```bash
php artisan translation:audit --no-progress --no-summary
```

To always hide them, set `disable_progress_bar` or `disable_summary` to `true` in the config file. Both options accept `true` or `false`, mean `true` when passed without a value, and override the config for a single run, so `--no-summary=false` prints the summary even when the config hides it.

The progress bar is shown only when the error output is a terminal, so it is hidden in CI, in agents and when the error output is redirected, whatever the option and the config. Pass `--ansi` to show it anyway, or set the `NO_COLOR` environment variable to hide it in a terminal too.

### Running the Audit from an AI Agent

Pass `--for-agent` when the audit is run by an AI agent or by a script that parses its output. The command prints only the JSON result on the standard output, on a single line, and `{"missing":{}}` when no translation is missing:

```bash
php artisan translation:audit --for-agent
```

```json
{"missing":{"app/Http/Controllers/HomeController.php":{"auth.failed":["it"],"messages.welcome":["en","it"]}}}
```

It is a shortcut for `--display-format=json --no-progress --no-summary`, and it takes precedence over these options and their configs. It also hides the messages on the error output, like the warnings about heavy scan paths and the path of the saved file, so the output is valid JSON even for agents that merge the standard and error outputs. Errors are still printed on the error output, and the exit code stays the same: non-zero when translations are missing or the audit fails.

The audit detects the most common AI agents, like Claude Code, Codex, Cursor, Gemini CLI and GitHub Copilot, through the [laravel/agent-detector](https://github.com/laravel/agent-detector) package, and prints the agent output for them without the option. When an agent is detected, the agent output is always printed, even with `--for-agent=false`.

### Finding Unused Translations

Pass `--unused` to also report the translations defined in your `lang` folder but used in none of the scanned files, or set `audit_unused` to `true` in the config file to report them on every run:

```bash
php artisan translation:audit --unused
```

The option accepts `true` or `false`, means `true` when passed without a value, and overrides the config for a single run. Both the JSON files, like `lang/it.json`, and the PHP files, like `lang/it/messages.php`, are audited, with the keys of the PHP files prefixed by their group, like `messages.welcome`.

The `list` and `table` formats print the missing and the unused translations under their own headings, with the unused keys grouped by locale and then by translation file, each followed by its translation. The `json` format adds them under the `unused` key, mapped to their translation:

```json
{"missing":{"app/Http/Controllers/HomeController.php":{"auth.failed":["it"]}},"unused":{"it":{"lang/it.json":{"Goodbye":"Arrivederci"},"lang/it/messages.php":{"messages.old":"Vecchio"}}}}
```

The summary then also counts the unused keys, like `Found 2 unused keys in 2 translation files.`

The translation files matching the `unused_ignore_paths` glob patterns in the config file, relative to the project root, are left out of this audit. By default they are the `auth.php`, `pagination.php`, `passwords.php` and `validation.php` files, whose keys Laravel itself uses. The ignored locales and keys are left out as well.

### Ignoring Keys

List the translation keys to leave out of the audit in the `ignore_keys` config. A plain key is ignored for every locale, while a key mapped to a list of locales is ignored only for those locales:

```php
'ignore_keys' => [
    'Hello',          // ignored for every locale
    'Hi' => ['en'],   // ignored only for English
],
```

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

The result is saved even when no translation is missing, and the command prints the path of the saved file on the error output. By default it lands in `storage/app/private/translation-audits`, in a file named like `translation-audit-30_Sep_2026_18_30-aB3dE9fG.json`.

The saved JSON has the same shape as the `json` display format: under the `missing` key it lists, for each scanned file, the keys with missing translations and the locales they are missing in, and under the `unused` key the unused translations when they are audited. It is `{"missing": {}}` when nothing is missing:

```json
{
    "missing": {
        "app/Http/Controllers/HomeController.php": {
            "messages.welcome": ["it", "fr"]
        }
    }
}
```

Each of these options overrides the matching config value for a single run:

| Option | Config | Default | Description |
| --- | --- | --- | --- |
| `--save` | `always_save` | `false` | Whether to save the audit result. Accepts `true` or `false`, and means `true` when passed without a value. |
| `--save-format` | `save_format` | `SaveFormat::Json` | The format of the saved file, a `TranslationAudit\Enums\SaveFormat` case or its value in the config. Supported formats: `json`. |
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
