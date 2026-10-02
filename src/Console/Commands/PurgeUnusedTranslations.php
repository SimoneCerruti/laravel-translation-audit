<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    protected function perform(): PurgeUnusedTranslationsResult {
        $unused = app(DetectUnusedTranslations::class)->handle(
            $this->findTranslationKeys(),
            $this->shared_config->locales(),
            $this->shared_config->unused_ignore_paths,
            $this->shared_config->ignore_keys,
        );

        return new PurgeUnusedTranslationsResult($this->config->is_dry_run ? $unused : $this->purge($unused));
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
