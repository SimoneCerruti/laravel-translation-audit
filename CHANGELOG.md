# Release Notes

## [Unreleased](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.11.0...HEAD)

## [v0.11.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.10.0...v0.11.0) - 2026-10-07

### Enhancements

- Add the `hooks` config, listing the classes run before and after the commands, resolved from the container once per run. Their `before` method is called before the scan, and their `after` method once the result is printed, both through the container, taking the command as the `$command` argument, the result as the `$result` argument of `after`, and any other dependency. A listed class runs on every command, a class mapped to a command class, or a list of command classes, like `NotifyTeam::class => AuditTranslations::class`, only on those. A failing hook fails the command, naming the hook

## [v0.10.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.9.0...v0.10.0) - 2026-10-05

### Enhancements

- Add the `additional_keys` config, listing the translation keys to audit even though the scan can't detect them, like those used only by the frontend. They are audited as used in `config/translation-audit.php`, so they are checked as missing in every locale, and never reported as unused nor purged. A key with an asterisk in place of each dynamic part, like `payments.*`, is audited as a dynamic key

## [v0.9.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.8.0...v0.9.0) - 2026-10-04

### Enhancements

- Add the `translation_calls` config, listing the custom translation functions, like `t`, and static methods, like `App\Support\Translator::translate`, whose keys are audited besides those of Laravel's own translation calls. A call takes the key as its first argument, or as the argument at the position it is mapped to, like `'trans_for' => 1`. Namespaced functions, imported classes and facade aliases are resolved, so the calls in the Blade views are detected too
- Detect the key passed by the named `key` argument to Laravel's translation calls, like `trans(replace: [...], key: 'messages.welcome')`
- List the keys used in a file in the order they appear, instead of grouping them by the kind of translation call

## [v0.8.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.7.0...v0.8.0) - 2026-10-04

### Enhancements

- Add the `resolvers` config, listing the classes implementing the `TranslationAudit\Contracts\TranslationKeyResolver` contract, resolved from the container. Each resolver returns the translation keys the app builds at runtime by custom logic, like the label keys derived from the cases of an enum, which are audited as used in a file named after the resolver class. Its `covers()` method maps the glob pattern of the files to the patterns of the dynamic keys it replaces, which are no longer audited by their pattern

## [v0.7.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.6.0...v0.7.0) - 2026-10-03

### Enhancements

- Check the dynamic keys for missing translations: a translation matching a dynamic key in one locale is reported as missing in the locales not defining it, and the pattern of the dynamic key, like `payments.*`, is reported as missing in every locale when no translation matches it. A dynamic key can be ignored by its pattern in the `ignore_keys` config.
- Add the `dynamic_keys` config, mapping the pattern of a dynamic key, like `payments.*`, to a backed enum class or to the list of its values. Each value is audited as a key of its own, so its missing translations are reported, and the translations matching the pattern but not among its values are reported as unused and removed by `translation:purge-unused`.

### Fixes

- Detect the translation keys built at runtime by interpolation or concatenation, like `__("payments.{$method}")` or `__('payments.'.$method)`, as dynamic keys. The translations matching a dynamic key are no longer reported as unused, nor removed by `translation:purge-unused`. Keys without any static text, like `__($key)`, are still skipped.

### Maintenance

- Require `phpstan/phpstan` ^2.2.14 in the development dependencies, so the prefer-lowest lane does not install a version reporting false collection type errors, and type the purged translations collection accordingly.

## [v0.6.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.5.0...v0.6.0) - 2026-10-02

### Enhancements

- Accept the `TranslationAudit\Enums\SaveFormat` and `TranslationAudit\Enums\DisplayFormat` cases in the `save_format` and `display_format` config, which now default to `SaveFormat::Json` and `DisplayFormat::List`. The string values keep working, so published config files need no change.
- Hide with the `--no-summary` option and the `disable_summary` config also the message printed when no translation is missing or unused, which is now the summary of a clean result.
- Add the `translation:purge-unused` command to remove the unused translations from the JSON and PHP translation files, printing the removed ones grouped by locale and translation file. Pass `--dry-run` to only list them without touching the files. The files matching `unused_ignore_paths` and the ignored locales and keys are left untouched.
  PHP translation files are rewritten, so their comments and formatting are lost, and the nested arrays left empty are removed.

### Maintenance

- Split the audit command into single-purpose actions for finding and scanning the files, detecting the locales and
  the missing and unused translations, saving and printing the result, with a common `ResultPrinter` contract for
  the printers.
- Move the lifecycle, the file scanning and the config shared by the commands scanning the app into the
  `AuditCommand` base class and the `SharedConfig` DTO, keeping the audit-only config in `AuditTranslationsConfig`.
  Extract the `SaveTarget` value object resolving the path of the saved result, the
  `AuditTranslationsResult::isClean()` check and the backed enum option parsing of `CommandOptionHelper`. The shared
  options are now listed after the audit-only ones in `translation:audit --help`.
- Give each command a `<Command>Result` in `TranslationAudit\Results`, implementing the `Result` contract whose
  `print()` prints it in the display format. `AuditCommand` appends the shared options to the signature of each
  command, prints the result returned by `perform()` followed by the `DisplayMessage` summary returned by
  `summarize()` on the error output, styled by its `MessageSeverity`, and exits with the code of `exitCode()`.
  `AuditResult` is now `AuditTranslationsResult`, which absorbs the json, list and table printers, and the
  `ResultPrinter` contract and `DisplayFormat::getPrinter()` are removed.
- Add the `PurgeTranslationsFromFile` action removing translation keys from a JSON or PHP translation file and
  returning the removed translations.
- Skip Rector's `PostIncDecToPreIncDecRector`, which conflicts with Pint's post increment style.                                                                                                                                    returning the removed translations.
- Skip Rector's `PostIncDecToPreIncDecRector`, which conflicts with Pint's post increment style.

### Documentation

- Rewrite the README to be shorter and easier to read, organized around the tasks, and document removing the unused translations.

**Full Changelog**: https://github.com/SimoneCerruti/laravel-translation-audit/compare/v0.5.0...v0.6.0

## [v0.5.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.4.0...v0.5.0) - 2026-10-01

### Breaking Changes

- Wrap the missing translations under the `missing` key in the `json` display format, the `--for-agent` output and the saved result, like `{"missing":{"app/Example.php":{"Hello":["it"]}}}`. An empty result is now `{"missing":{}}` instead of `{}` or `[]`.

### Enhancements

- Add the `--unused` option and the `audit_unused` config to also report the translations defined in the `lang` folder but used in none of the scanned files, grouped by locale and translation file, with their translation.
- Detect when the audit is run by an AI agent and print the `--for-agent` output without the option. When an agent is detected, the agent output is printed even with `--for-agent=false`.
- Add the `unused_ignore_paths` config to leave translation files out of the unused audit, by default the `auth.php`, `pagination.php`, `passwords.php` and `validation.php` files Laravel itself uses.

### Documentation

- Document finding unused translations and the new shape of the JSON result.
- Document the AI agent detection.

**Full Changelog**: https://github.com/SimoneCerruti/laravel-translation-audit/compare/v0.4.0...v0.5.0

## [v0.4.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.3.1...v0.4.0) - 2026-10-01

### Breaking Changes

- Print only the result on the standard output, and the summary, the messages and the errors on the error output, so piping or redirecting the standard output captures only the result. Scripts reading the summary or the messages from the standard output have to read them from the error output.

### Enhancements

- Add the `--no-progress` option and the `disable_progress_bar` config to hide the progress bar while the files are scanned.
- Add the `--no-summary` option and the `disable_summary` config to hide the summary printed after the result.
- Add the `--for-agent` option to print only the JSON result, for the invocation by an AI agent. It hides the progress bar, the summary and the messages, like the heavy path warnings and the path of the saved file, so the output is valid JSON even when the standard and error outputs are merged. Errors are still printed. It takes precedence over `--display-format`, `--no-progress`, `--no-summary` and their configs.
- Show the progress bar only when the error output is a terminal, so it no longer fills CI and agent logs. Pass `--ansi` to show it anyway.

### Bug Fixes

- Print `{}` with the `json` display format when no translation is missing, instead of the `No missing translations found.` text, so the output is always valid JSON. The message is now printed on the error output.

### Documentation

- Document the output streams, hiding the progress bar and the summary, and the output for AI agents.

**Full Changelog**: https://github.com/SimoneCerruti/laravel-translation-audit/compare/v0.3.1...v0.4.0

## [v0.3.1](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.3.0...v0.3.1) - 2026-09-30

### Bug Fixes

- Replace the colons in the date printed by the `{now}` placeholder of `--save-name` and `save_name` with dashes, so formats like `{now:H:i}` or `{now:c}` produce valid file names on Windows. Slashes and backslashes are now replaced after formatting, so escaped characters in the format keep working.
- Save the JSON result without escaping slashes and Unicode characters.

**Full Changelog**: https://github.com/SimoneCerruti/laravel-translation-audit/compare/v0.3.0...v0.3.1

## [v0.3.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.2.0...v0.3.0) - 2026-09-30

### Enhancements

- Add the `ignore_keys` config to leave translation keys out of the audit, for every locale or only for the listed ones.
- Add the `--display-format` option and the `display_format` config to print the missing translations as a `table`, a `list` or `json`, followed by a summary of the keys and files with missing translations.
- Print the missing translations as a list grouped by file by default instead of a table, with the locales aligned before each key and long keys wrapped to the terminal width.

### Documentation

- Document ignoring keys, choosing the display format and the default list output.

**Full Changelog**: https://github.com/SimoneCerruti/laravel-translation-audit/compare/v0.2.0...v0.3.0

## [v0.2.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.1.0...v0.2.0) - 2026-09-30

### Enhancements

- Add the `always_follow_links` config to follow symbolic links on every run. The `--follow-links` option now also accepts `true` or `false` to override it for a single run.
- Add the `--save`, `--save-format`, `--save-path` and `--save-name` options, and the matching `always_save`, `save_format`, `save_path` and `save_name` config, to save the audit result to a JSON file.
- Add the `dev` keyword to the package, so Composer suggests installing it in `require-dev`.

### Documentation

- Document following symbolic links, saving the audit result and installing the package as a dev dependency.

**Full Changelog**: https://github.com/SimoneCerruti/laravel-translation-audit/compare/v0.1.0...v0.2.0

## [v0.1.0](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.1.0...v0.1.0) - 2026-09-29

<!-- Release notes generated using configuration in .github/release.yml at v0.1.0 -->
**Full Changelog**: https://github.com/SimoneCerruti/laravel-translation-audit/commits/v0.1.0
