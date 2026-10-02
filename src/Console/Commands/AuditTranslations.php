<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use TranslationAudit\Actions\BuildAuditResult;
use TranslationAudit\Actions\PrintAuditSummary;
use TranslationAudit\Actions\SaveAuditResult;
use TranslationAudit\Data\AuditTranslationsConfig;
use TranslationAudit\Data\AuditTranslationsResult;
use TranslationAudit\Data\SaveTarget;
use TranslationAudit\Enums\DisplayFormat;

/**
 * The missing locales of each translation key, grouped by the path of the file using the key.
 *
 * @phpstan-type MissingTranslations array<string, non-empty-array<non-falsy-string, non-empty-list<string>>>
 * @phpstan-type UnusedTranslations array<string, non-empty-array<string, non-empty-array<non-falsy-string, string>>>
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
    TXT.self::SHARED_OPTIONS;

    /** @var string */
    protected $description = 'Audit your app for missing or unused translations.';

    private AuditTranslationsConfig $config;

    protected function resolveConfig(): void {
        $this->config = AuditTranslationsConfig::fromInput($this->options_helper);
    }

    protected function perform(): int {
        $result = app(BuildAuditResult::class)->handle(
            $this->findTranslationKeys(),
            $this->shared_config->locales(),
            $this->shared_config->ignore_keys,
            $this->config->audit_unused,
            $this->shared_config->unused_ignore_paths,
        );

        if ($this->config->save_target instanceof SaveTarget) {
            $path = app(SaveAuditResult::class)->handle($result, $this->config->save_target);

            $this->printMessage("Audit result saved: {$path}", 'info');
        }

        if ($result->isClean()) {
            if ($this->shared_config->display_format === DisplayFormat::Json) {
                $this->printAuditResult($result);
            }

            $this->printMessage($this->config->audit_unused ? 'No missing or unused translations found.' : 'No missing translations found.', 'info');

            return self::SUCCESS;
        }

        $this->printAuditResult($result);

        if (! $this->shared_config->disable_summary) {
            $this->output->getErrorStyle()->newLine();
            app(PrintAuditSummary::class)->handle($result, $this->output->getErrorStyle());
        }

        return self::FAILURE;
    }

    private function printAuditResult(AuditTranslationsResult $result): void {
        $this->shared_config->display_format->getPrinter()->handle($result, $this->output);
    }
}
