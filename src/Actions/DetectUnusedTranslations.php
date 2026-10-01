<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use Symfony\Component\Finder\Glob;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

use function Safe\preg_match;

final readonly class DetectUnusedTranslations {
    public function __construct(private GetAppTranslationsForLocale $get_app_translations_for_locale) {}

    /**
     * Detect the translations defined in the translation files of each locale but used in none of the scanned files.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys  The keys used in the scanned files.
     * @param  array<string>  $locales
     * @param  array<string>  $ignore_paths  The glob patterns of the translation files to skip, relative to the project root.
     * @return Collection<int, Translation>
     */
    public function handle(Collection $translation_keys, array $locales, array $ignore_paths, IgnoredKeys $ignored_keys): Collection {
        $used_keys = $translation_keys->keyBy('value');

        return new Collection($locales)
            ->flatMap(fn (string $locale): Collection => $this->get_app_translations_for_locale->handle($locale))
            ->reject(fn (Translation $translation): bool => $this->isIgnoredPath($translation->file, $ignore_paths)
                || $used_keys->has($translation->key)
                || $ignored_keys->has($translation->key, $translation->locale))
            ->values();
    }

    /** @param  array<string>  $ignore_paths */
    private function isIgnoredPath(string $relative_path, array $ignore_paths): bool {
        return array_any($ignore_paths, fn (string $ignore_path): bool => preg_match(Glob::toRegex($ignore_path), $relative_path) === 1);
    }
}
