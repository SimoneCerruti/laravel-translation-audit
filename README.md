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

A key returning an array uses all the translations nested in it: with `__('settings.languages')`, every `settings.languages.*` translation counts as used.

Laravel's own translation files (`auth.php`, `pagination.php`, `passwords.php` and `validation.php`) are skipped, since the framework uses them, whether your `lang` folder is at the project root or in `resources`. You can change this list with the `unused_ignore_paths` config.

## Custom Translation Calls

The audit reads the keys passed to Laravel's own translation calls: `__()`, `trans()`, `trans_choice()`, the `Lang` facade, and the translator returned by `trans()` and `app('translator')`. If your app translates through its own helpers, list them in the `translation_calls` config, or their keys are reported as unused, and removed by the purge:

```php
'translation_calls' => [
    't',                                                // t('messages.welcome')
    'App\Support\t',                                    // a namespaced function
    App\Support\Translator::class.'::translate',        // Translator::translate('messages.welcome')
    'Illuminate\Support\Facades\Lang::customTranslate', // a macro on the Lang facade
    'trans_for' => 1,                                   // trans_for($locale, 'messages.welcome')
],
```

A function or static method listed on its own takes the key as its first argument. When the key comes later, map the call to the position of its argument, starting from 0. Name the functions and classes in full, as the facade aliases used in the Blade views, like `Lang`, are resolved to their class.

Only calls passing the key by position are read, not by a named argument. Calls on an instance, like `$translator->translate('messages.welcome')`, can't be followed by the scan: list the keys they use in [`additional_keys`](#additional-keys), or return them from a [custom resolver](#custom-resolvers). A custom Blade directive is read through the code it compiles to, so `@t('messages.welcome')` compiling to `t('messages.welcome')` only needs `t` in the config.

## Dynamic Keys

Keys built at runtime, like `__("payments.{$method}")` or `__('payments.'.$method)`, can't be checked one by one, since their values are unknown. The audit reads them as the pattern `payments.*` instead:

- every translation matching the pattern counts as used, so it's never reported as unused, nor removed;
- a translation matching the pattern in one locale is reported as missing in the locales without it, e.g. `payments.paypal` defined in `en` but not in `it`;
- when no locale has a translation matching the pattern, the pattern itself is reported as missing.

To leave a dynamic key out, list its pattern in `ignore_keys`, e.g. `'payments.*'`. Keys without any fixed text, like `__($key)`, are skipped: list the translations they use in [`additional_keys`](#additional-keys).

When you know the values a dynamic key can take, list them in the `dynamic_keys` config, as a backed enum or a list. Each value is then audited as a key of its own: `payments.card` is reported as missing wherever it is, and a `payments.*` translation that isn't among the values is reported as unused, and removed by the purge.

```php
'dynamic_keys' => [
    'payments.*' => PaymentMethod::class,              // the values of the enum cases
    'status.*.label' => ['active', 'suspended'],
],
```

A pattern has a single asterisk, in place of the dynamic part of the key.

## Custom Resolvers

Some keys are built by logic that a scan can't follow, and that a list of values can't describe, like a label key derived from the enum case:

```php
trait HasLabel
{
    public function label(): string
    {
        return __('orders.status.'.str($this->value)->replace('_', '-'));
    }
}
```

For these, write a resolver: a class implementing `TranslationAudit\Contracts\TranslationKeyResolver`, which returns the keys the app uses at runtime, and the dynamic keys they replace:

```php
use TranslationAudit\Contracts\TranslationKeyResolver;

class OrderStatusLabelKeys implements TranslationKeyResolver
{
    public function resolve(): iterable
    {
        foreach (OrderStatus::cases() as $case) {
            yield 'orders.status.'.str($case->value)->replace('_', '-');
        }
    }

    public function covers(): array
    {
        return ['app/Enums/Concerns/HasLabel.php' => 'orders.status.*'];
    }
}
```

Then list it in the `resolvers` config:

```php
'resolvers' => [
    App\Translations\OrderStatusLabelKeys::class,
],
```

The resolvers are resolved from the container, so their constructor can take any dependency. The keys they return are audited as used in a file named after the resolver class, which is where their missing translations are reported. A resolver returns the keys, not their translations.

`covers()` maps a glob pattern of the files, relative to the project root, to the pattern, or the list of patterns, of the dynamic keys the resolver replaces. Those dynamic keys are dropped, so a translation matching them but not among the resolved keys is reported as unused, and removed by the purge. The other keys of the covered files are still audited. Return `[]` when the resolver replaces no dynamic key.

Keys without any fixed text, like `__($key)`, are skipped by the scan, so a resolver returning the keys they use needs no `covers()`.

## Hooks

To run your own code around a command, like pulling the translations from your translation service before the audit, or notifying your team of its result, write a hook: a class with a `before` method, called before the files are scanned, an `after` method, called once the result is printed, or both:

```php
use TranslationAudit\Console\Commands\AuditCommand;
use TranslationAudit\Results\Contracts\Result;

class NotifyTeam
{
    public function before(AuditCommand $command): void
    {
        // ...
    }

    public function after(Result $result, Slack $slack): void
    {
        if (! $result->isClean()) {
            $slack->send('Some translations need attention.');
        }
    }
}
```

Then list it in the `hooks` config. A listed hook runs on every command, a hook mapped to a command class, or a list of command classes, only on those:

```php
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Console\Commands\PurgeUnusedTranslations;

'hooks' => [
    App\Translations\PullTranslations::class,                         // every command
    App\Translations\NotifyTeam::class => AuditTranslations::class,   // only translation:audit
    App\Translations\CommitLangFiles::class => [PurgeUnusedTranslations::class],
],
```

The hooks run in the order of the config. Each one is resolved from the container once per run, so its `before` and `after` methods share the instance. The methods are called through the container: the command is passed to the `$command` argument, or to an argument typed as the command class, the result to the `$result` argument of `after`, or to an argument typed as the result class, and any other dependency is injected.

A hook throwing an exception fails the command with its message. A failing `before` hook stops the command before the scan, while a failing `after` hook runs once the command is done, so the purge has already removed the unused translations.

## Additional Keys

Some keys never appear in the scanned files, like those used only by your frontend, or read from the database. List them in the `additional_keys` config to audit them anyway:

```php
'additional_keys' => [
    'messages.welcome',
    'payments.*',      // audited as a dynamic key
],
```

The additional keys are audited as used in `config/translation-audit.php`, which is where their missing translations are reported. They are never reported as unused, nor removed by the purge. A key with an asterisk in place of each dynamic part, like `payments.*`, is audited as a [dynamic key](#dynamic-keys), expanded by the `dynamic_keys` config when it lists its values. `ignore_keys` still applies to them.

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

When the scan [skips a file](#configuration), or a translation file can't be read, the JSON result, printed or saved, also has a `skipped` section, with the reason why each skipped file can't be scanned or read, keyed by its path:

```json
{"missing":{},"skipped":{"resources/views/kits/edit.blade.php":"Syntax error, unexpected '<' on line 2"}}
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
| `translation_calls` | Your own [translation functions and static methods](#custom-translation-calls), besides Laravel's. |
| `ignore_keys` | The keys to leave out, for every locale or only for some. |
| `additional_keys` | The [keys to audit](#additional-keys) even though the scan can't detect them. |
| `dynamic_keys` | The values of the [dynamic keys](#dynamic-keys), to audit each value as a key of its own. |
| `resolvers` | The [custom resolvers](#custom-resolvers) of the keys built at runtime by your own logic. |
| `hooks` | The [hooks](#hooks) run before and after the commands. |
| `unused_ignore_paths` | The translation files never reported as unused, nor removed. |

To ignore a key everywhere, list it on its own. To ignore it only for some locales, map it to them:

```php
'ignore_keys' => [
    'Hello',          // ignored for every locale
    'Hi' => ['en'],   // ignored only for English
    'payments.*',     // a dynamic key, like __("payments.{$method}"), ignored by its pattern
],
```

A file that can't be scanned, like a broken view or one using a Blade component that isn't registered, is skipped with a warning naming it, and the audit goes on with the other files. The JSON result lists it in its [`skipped` section](#output-formats). The translations it uses aren't audited, so they may be reported as unused: fix the file, or add it to `ignore_paths`. The purge removes nothing while a file is skipped, since it would remove the translations that file uses, but a dry run still lists them.

A translation file that can't be read, like one with a syntax error or an empty PHP file that doesn't return an array, is skipped the same way, with a warning naming it, and listed in the `skipped` section too. Its translations aren't audited, so the keys the translator can't load in its locale aren't reported as missing, while the other locales and translation files are still audited. The purge leaves it untouched and goes on with the other translation files.

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
