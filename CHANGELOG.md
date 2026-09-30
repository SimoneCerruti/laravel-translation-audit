# Release Notes

## [Unreleased](https://github.com/simonecerruti/laravel-translation-audit/compare/v0.3.0...HEAD)

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
