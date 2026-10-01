# Release Notes

## [Unreleased](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.4.0...HEAD)

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

### Breaking Changes

- Wrap the missing translations under the `missing` key in the `json` display format, the `--for-agent` output and the saved result, like `{"missing":{"app/Example.php":{"Hello":["it"]}}}`. An empty result is now `{"missing":{}}` instead of `{}` or `[]`.

### Enhancements

- Add the `--unused` option and the `audit_unused` config to also report the translations defined in the `lang` folder but used in none of the scanned files, grouped by locale and translation file, with their translation.
- Add the `unused_ignore_paths` config to leave translation files out of the unused audit, by default the `auth.php`, `pagination.php`, `passwords.php` and `validation.php` files Laravel itself uses.

### Documentation

- Document finding unused translations and the new shape of the JSON result.

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
