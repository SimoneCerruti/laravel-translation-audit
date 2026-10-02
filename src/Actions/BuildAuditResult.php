<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use TranslationAudit\Data\AuditTranslationsResult;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

final readonly class BuildAuditResult {
    public function __construct(
        private DetectMissingTranslations $detect_missing_translations,
        private DetectUnusedTranslations $detect_unused_translations,
    ) {}

    /**
     * Audit the keys used in the scanned files for the missing translations and, when asked to, for the unused ones.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys  The keys used in the scanned files.
     * @param  array<string>  $locales
     * @param  array<string>  $unused_ignore_paths  The glob patterns of the translation files to skip while auditing the unused translations, relative to the project root.
     */
    public function handle(Collection $translation_keys, array $locales, IgnoredKeys $ignored_keys, bool $with_unused, array $unused_ignore_paths): AuditTranslationsResult {
        $missing = $this->detect_missing_translations->handle($translation_keys, $locales, $ignored_keys);
        $result = new AuditTranslationsResult($missing);

        if (! $with_unused) {
            return $result;
        }

        $unused = $this->detect_unused_translations->handle($translation_keys, $locales, $unused_ignore_paths, $ignored_keys);

        return $result->withUnused($unused);
    }
}
