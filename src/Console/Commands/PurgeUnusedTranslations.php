<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use TranslationAudit\Actions\DetectUnusedTranslations;
use TranslationAudit\Actions\PurgeTranslationsFromFile;
use TranslationAudit\Data\DisplayMessage;
use TranslationAudit\Data\PurgeUnusedTranslationsConfig;
use TranslationAudit\Data\Translation;
use TranslationAudit\Enums\MessageSeverity;
use TranslationAudit\Results\Contracts\Result;
use TranslationAudit\Results\PurgeUnusedTranslationsResult;

/** @extends AuditCommand<PurgeUnusedTranslationsResult> */
class PurgeUnusedTranslations extends AuditCommand {
    /** @var string */
    protected $signature = <<<'TXT'
        translation:purge-unused
            {--dry-run= : List the unused translations that would be purged without removing them from the translation files. Accept true or false, if no value is specified it defaults to true}
    TXT;

    /** @var string */
    protected $description = 'Purge unused translations.';

    /** The keys named for each translation file and reason in the warning for the translations that cannot be purged, unless the output is verbose. */
    private const int NOT_PURGED_KEYS_SHOWN = 5;

    private PurgeUnusedTranslationsConfig $config;

    protected function resolveConfig(): void {
        $this->config = PurgeUnusedTranslationsConfig::fromInput($this->options_helper);
    }

    /**
     * Detect the unused translations and purge them, unless it's a dry run.
     * Nothing is purged when a file is skipped by the scan, since the translations it uses would look unused,
     * while a translation file that cannot be read is skipped, with its translations left untouched.
     * The unused translations that cannot be purged are left untouched with a warning, even on a dry run.
     *
     * @throws RuntimeException When a file is skipped by the scan, outside a dry run.
     */
    protected function perform(): PurgeUnusedTranslationsResult {
        $translation_keys = $this->findTranslationKeys();

        if ($this->skipped_files !== [] && ! $this->config->is_dry_run) {
            throw new RuntimeException('Nothing purged: the translations used by the skipped files would be purged too. Fix them, or add them to the ignore_paths config, then run the purge again.');
        }

        $unused = app(DetectUnusedTranslations::class)->handle(
            $translation_keys,
            $this->shared_config->locales(),
            $this->shared_config->unused_ignore_paths,
            $this->shared_config->ignore_keys,
            $this->skipTranslationFile(...),
        );
        $skipped = $this->warnForSkippedTranslationFiles();

        [$purged, $not_purged] = $this->purge($unused);
        $this->warnForNotPurged($not_purged);

        return new PurgeUnusedTranslationsResult($purged, $skipped, $not_purged);
    }

    /**
     * Remove the unused translations from their translation files, unless it's a dry run,
     * returning the ones removed, or that would be removed, along with the reason why each of the others cannot be removed, grouped by locale and translation file.
     *
     * @param  Collection<int, Translation>  $unused
     * @return array{Collection<int, Translation>, array<string, array<string, array<string, string>>>}
     */
    private function purge(Collection $unused): array {
        $purge_translations_from_file = app(PurgeTranslationsFromFile::class);
        /** @var Collection<int, Translation> $purged */
        $purged = new Collection;
        $not_purged = [];

        foreach ($unused->groupBy('file') as $file => $translations) {
            $result = $purge_translations_from_file->handle((string) $file, $translations->pluck('key'), $this->config->is_dry_run);

            foreach ($translations as $translation) {
                if ($result->purged->has($translation->key)) {
                    $purged->push($translation);
                } elseif ($result->not_purged->has($translation->key)) {
                    $not_purged[$translation->locale][$translation->file][$translation->key] = $result->not_purged->get($translation->key);
                }
            }
        }

        return [$purged, $not_purged];
    }

    /**
     * Warn for the unused translations that cannot be purged, even for an agent, naming their keys for each translation file and reason.
     * Only the first keys are named unless the output is verbose.
     *
     * @param  array<string, array<string, array<string, string>>>  $not_purged
     */
    private function warnForNotPurged(array $not_purged): void {
        $lines = [];
        $count = 0;
        $is_truncated = false;

        foreach ($not_purged as $files) {
            foreach ($files as $file => $reasons) {
                $keys_by_reason = [];

                foreach ($reasons as $key => $reason) {
                    $keys_by_reason[$reason][] = $key;
                }

                foreach ($keys_by_reason as $reason => $keys) {
                    $count += \count($keys);
                    $shown_keys = $this->output->isVerbose() ? $keys : \array_slice($keys, 0, self::NOT_PURGED_KEYS_SHOWN);
                    $hidden_count = \count($keys) - \count($shown_keys);
                    $is_truncated = $is_truncated || $hidden_count > 0;
                    $named_keys = implode(', ', $shown_keys).($hidden_count > 0 ? " and {$hidden_count} more" : '');

                    $lines[] = "Unable to purge {$named_keys} from {$file}: ".Str::lcfirst($reason);
                }
            }
        }

        if ($lines === []) {
            return;
        }

        $translations = Str::plural('translation', $count);
        $pronoun = $count === 1 ? 'it' : 'them';
        $lines[] = "{$count} unused {$translations} cannot be purged: remove {$pronoun} by hand, or list {$pronoun} in the ignore_keys config.".($is_truncated ? ' Run the command with -v to name all the keys.' : '');

        $this->printWarning($lines);
    }

    protected function summarize(Result $result): DisplayMessage {
        if ($result->isClean()) {
            return new DisplayMessage('No unused translations found.', MessageSeverity::Info);
        }

        $count = $result->unused->count();
        $translations = Str::plural('translation', $count);
        $message = $this->config->is_dry_run ? "{$count} unused {$translations} would be purged" : "{$count} unused {$translations} purged";
        $not_purged_count = $result->countNotPurged();

        return new DisplayMessage($not_purged_count > 0 ? "{$message}, {$not_purged_count} cannot be purged." : "{$message}.", MessageSeverity::Info);
    }
}
