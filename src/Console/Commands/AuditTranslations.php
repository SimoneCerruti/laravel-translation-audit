<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use TranslationAudit\Actions\DetectMissingTranslations;
use TranslationAudit\Actions\DetectUnusedTranslations;
use TranslationAudit\Actions\SaveAuditResult;
use TranslationAudit\Data\AuditTranslationsConfig;
use TranslationAudit\Data\DisplayMessage;
use TranslationAudit\Data\SaveTarget;
use TranslationAudit\Enums\MessageSeverity;
use TranslationAudit\Results\AuditTranslationsResult;
use TranslationAudit\Results\Contracts\Result;

/**
 * The missing locales of each translation key, grouped by the path of the file using the key.
 *
 * @phpstan-type MissingTranslations array<string, non-empty-array<non-falsy-string, non-empty-list<string>>>
 * @phpstan-type UnusedTranslations array<string, non-empty-array<string, non-empty-array<non-falsy-string, string>>>
 *
 * @extends AuditCommand<AuditTranslationsResult>
 */
class AuditTranslations extends AuditCommand {
    /** @var string */
    protected $signature = <<<'TXT'
        translation:audit
            {--save= : Persist the audit result. Accept true or false, if no value is specified it defaults to true. Overrides the always_save config}
            {--save-format= : The format in which to save the audit result. Supported formats: json. Overrides the save_format config}
            {--save-path= : The path in which to save the audit result. Overrides the save_path config}
            {--save-name= : The name of the file to save the audit result to, without the extension. It supports the following patterns, which have to be wrapped in curly braces: - now:<format>: inserts the current date in the specified format; - random:<length>: inserts random alphanumeric characters (a-zA-Z0-9). Overrides the save_name config}
            {--unused= : Also audit for unused translations, defined in the translation files but used in none of the scanned files. Accept true or false, if no value is specified it defaults to true. Overrides the audit_unused config}
    TXT;

    /** @var string */
    protected $description = 'Audit your app for missing or unused translations.';

    private AuditTranslationsConfig $config;

    protected function resolveConfig(): void {
        $this->config = AuditTranslationsConfig::fromInput($this->options_helper);
    }

    protected function perform(): AuditTranslationsResult {
        $translation_keys = $this->findTranslationKeys();
        $locales = $this->shared_config->locales();

        $result = new AuditTranslationsResult(app(DetectMissingTranslations::class)->handle($translation_keys, $locales, $this->shared_config->ignore_keys), skipped: $this->skipped_files);

        if ($this->config->audit_unused) {
            $result = $result->withUnused(app(DetectUnusedTranslations::class)->handle($translation_keys, $locales, $this->shared_config->unused_ignore_paths, $this->shared_config->ignore_keys));
        }

        if ($this->config->save_target instanceof SaveTarget) {
            $path = app(SaveAuditResult::class)->handle($result, $this->config->save_target);

            $this->printMessage(new DisplayMessage("Audit result saved: {$path}", MessageSeverity::Info));
        }

        return $result;
    }

    protected function summarize(Result $result): DisplayMessage {
        if ($result->isClean()) {
            return new DisplayMessage($this->config->audit_unused ? 'No missing or unused translations found.' : 'No missing translations found.', MessageSeverity::Info);
        }

        $lines = [];

        if ($result->missing->isNotEmpty()) {
            $missing = $result->missingByFile();
            $keys_count = $missing->sum(fn (Collection $keys): int => $keys->count());
            $files_count = $missing->count();

            $lines[] = \sprintf('Found %d %s with missing translations in %d %s.', $keys_count, Str::plural('key', $keys_count), $files_count, Str::plural('file', $files_count));
        }

        if ($result->unused instanceof Collection && $result->unused->isNotEmpty()) {
            $keys_count = $result->unused->count();
            $files_count = $result->unused->pluck('file')->unique()->count();

            $lines[] = \sprintf('Found %d unused %s in %d translation %s.', $keys_count, Str::plural('key', $keys_count), $files_count, Str::plural('file', $files_count));
        }

        return new DisplayMessage(implode(PHP_EOL, $lines), MessageSeverity::Error);
    }

    protected function exitCode(Result $result): int {
        return $result->isClean() ? self::SUCCESS : self::FAILURE;
    }
}
