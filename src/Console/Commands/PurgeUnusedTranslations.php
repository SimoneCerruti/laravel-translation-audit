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

    private PurgeUnusedTranslationsConfig $config;

    protected function resolveConfig(): void {
        $this->config = PurgeUnusedTranslationsConfig::fromInput($this->options_helper);
    }

    /**
     * Detect the unused translations and purge them, unless it's a dry run.
     * Nothing is purged when a file is skipped by the scan, since the translations it uses would look unused,
     * while a translation file that cannot be read is skipped, with its translations left untouched.
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

        return new PurgeUnusedTranslationsResult($this->config->is_dry_run ? $unused : $this->purge($unused), $skipped);
    }

    /**
     * Remove the unused translations from their translation files, keeping only the ones actually removed.
     *
     * @param  Collection<int, Translation>  $unused
     * @return Collection<int, Translation>
     */
    private function purge(Collection $unused): Collection {
        $purge_translations_from_file = app(PurgeTranslationsFromFile::class);

        return $unused
            ->groupBy('file')
            ->flatMap(function (Collection $translations, string $file) use ($purge_translations_from_file): Collection {
                $purged = $purge_translations_from_file->handle($file, $translations->pluck('key'));

                return $translations->filter(fn (Translation $translation): bool => $purged->has($translation->key));
            })
            ->values();
    }

    protected function summarize(Result $result): DisplayMessage {
        if ($result->isClean()) {
            return new DisplayMessage('No unused translations found.', MessageSeverity::Info);
        }

        $count = $result->unused->count();
        $translations = Str::plural('translation', $count);

        return new DisplayMessage($this->config->is_dry_run ? "{$count} unused {$translations} would be purged." : "{$count} unused {$translations} purged.", MessageSeverity::Info);
    }
}
