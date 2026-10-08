<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use RuntimeException;
use Throwable;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

final readonly class DetectMissingTranslations {
    public function __construct(private GetAppTranslationsForLocale $get_app_translations_for_locale) {}

    /**
     * Detect the translations missing in each locale for the keys used in each file.
     * The values of a dynamic key are unknown, so a key matching it in a locale is missing in the locales not defining it,
     * and the dynamic key, by its pattern, is missing in every locale when no key matches it.
     * A translation file that cannot be read fails, unless a callback for the skipped files is given: then the keys the translator cannot load in its locale are not detected.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys  The keys used in the scanned files.
     * @param  array<string>  $locales
     * @param  (Closure(string, RuntimeException): void)|null  $on_file_skipped  Called for each translation file that cannot be read, with its path relative to the project root and the error naming it.
     * @return Collection<int, Translation>
     *
     * @throws RuntimeException Naming the translation file that cannot be read, without a callback for the skipped files.
     */
    public function handle(Collection $translation_keys, array $locales, IgnoredKeys $ignored_keys, ?Closure $on_file_skipped = null): Collection {
        $missing = new Collection;
        $defined_keys = [];
        $locales_with_skipped_files = [];

        // reading the translation files first names the one that cannot be read, which the translator would fail to load without naming it.
        foreach ($locales as $locale) {
            $defined_keys[$locale] = $this->get_app_translations_for_locale
                ->handle($locale, $on_file_skipped instanceof Closure ? function (string $path, RuntimeException $error) use ($locale, &$locales_with_skipped_files, $on_file_skipped): void {
                    $locales_with_skipped_files[$locale] = true;
                    $on_file_skipped($path, $error);
                } : null)
                ->pluck('key')->unique()->all();
        }

        $dynamic_keys = [];
        // the same key used more than once in a file is detected once.
        // keyBy deduplicates in linear time, unlike unique() with a callback, which is quadratic.
        $unique_keys = $translation_keys->keyBy(fn (UsedTranslationKey $key): string => json_encode([$key->file, $key->value], JSON_THROW_ON_ERROR));

        foreach ($unique_keys as $key) {
            if ($key->value instanceof DynamicTranslationKey) {
                $dynamic_keys[] = [$key->file, $key->value];

                continue;
            }

            foreach ($locales as $locale) {
                if ($ignored_keys->has($key->value, $locale)) {
                    continue;
                }

                if (! $this->hasForLocale($key->value, $locale, isset($locales_with_skipped_files[$locale]))) {
                    $missing->push(new Translation($key->value, $locale, $key->file));
                }
            }
        }

        if ($dynamic_keys !== []) {
            $missing->push(...$this->detectMissingForDynamicKeys($dynamic_keys, $defined_keys, $ignored_keys));
        }

        // a key used both statically and dynamically in a file is detected once.
        return $missing
            ->keyBy(fn (Translation $translation): string => json_encode([$translation->key, $translation->locale, $translation->file], JSON_THROW_ON_ERROR))
            ->values();
    }

    /**
     * Whether the translator has the key in the locale, or, when a translation file of the locale is skipped, cannot load it, so it is not detected as missing.
     *
     * @throws Throwable When the translator cannot load the key, while no translation file of the locale is skipped.
     */
    private function hasForLocale(string $key, string $locale, bool $has_skipped_files): bool {
        try {
            return Lang::hasForLocale($key, $locale);
        } catch (Throwable $e) {
            if ($has_skipped_files) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * @param  non-empty-list<array{string, DynamicTranslationKey}>  $dynamic_keys  The dynamic keys with the path of the file using them.
     * @param  array<string, array<array-key, non-falsy-string>>  $defined_keys  The keys defined in the translation files of each locale.
     * @return list<Translation>
     */
    private function detectMissingForDynamicKeys(array $dynamic_keys, array $defined_keys, IgnoredKeys $ignored_keys): array {
        $locales = array_keys($defined_keys);
        $missing = [];

        foreach ($dynamic_keys as [$file, $dynamic_key]) {
            $matching_keys = array_map(fn (array $keys): array => array_filter($keys, $dynamic_key->matches(...)), $defined_keys);
            $all_matching_keys = array_unique(array_merge(...array_values($matching_keys)));

            foreach ($locales as $locale) {
                if ($ignored_keys->has($dynamic_key->pattern(), $locale)) {
                    continue;
                }

                $missing_keys = $all_matching_keys === [] ? [$dynamic_key->pattern()] : array_diff($all_matching_keys, $matching_keys[$locale]);

                foreach ($missing_keys as $missing_key) {
                    if (! $ignored_keys->has($missing_key, $locale)) {
                        $missing[] = new Translation($missing_key, $locale, $file);
                    }
                }
            }
        }

        return $missing;
    }
}
