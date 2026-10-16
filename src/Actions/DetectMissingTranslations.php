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
use TranslationAudit\Enums\AuditType;
use TranslationAudit\Support\IgnoredKeys;

/** @internal */
final readonly class DetectMissingTranslations {
    public function __construct(private GetAppTranslationsForLocale $get_app_translations_for_locale) {}

    /**
     * Detect the translations missing in each locale for the keys used in each file.
     * The values of a dynamic key are unknown, so a key matching it in a locale is missing in the locales where the translator lacks it,
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
        $defined_keys = [];
        $locales_with_skipped_files = [];

        // reading the translation files first names the one that cannot be read, which the translator would fail to load without naming it.
        foreach ($locales as $locale) {
            $on_locale_file_skipped = null;

            if ($on_file_skipped instanceof Closure) {
                $on_locale_file_skipped = function (string $path, RuntimeException $error) use ($locale, $on_file_skipped, &$locales_with_skipped_files): void {
                    $locales_with_skipped_files[] = $locale;
                    $on_file_skipped($path, $error);
                };
            }

            $defined_keys[$locale] = $this->getDefinedKeys($locale, $on_locale_file_skipped);
        }

        // the same key used more than once in a file is detected once.
        // keyBy deduplicates in linear time, unlike unique() with a callback, which is quadratic.
        $unique_keys = $translation_keys->keyBy(fn (UsedTranslationKey $key): string => json_encode([$key->file, $key->value], JSON_THROW_ON_ERROR));
        $missing = new Collection;

        // the static keys are detected before the dynamic ones, so they are listed first.
        foreach ($unique_keys as $key) {
            if (\is_string($key->value)) {
                $missing->push(...$this->detectMissingForKey($key->value, $key->file, $locales, $ignored_keys, $locales_with_skipped_files));
            }
        }

        foreach ($unique_keys as $key) {
            if ($key->value instanceof DynamicTranslationKey) {
                $missing->push(...$this->detectMissingForDynamicKey($key->value, $key->file, $defined_keys, $ignored_keys, $locales_with_skipped_files));
            }
        }

        // a key used both statically and dynamically in a file is detected once.
        return $missing
            ->keyBy(fn (Translation $translation): string => json_encode([$translation->key, $translation->locale, $translation->file], JSON_THROW_ON_ERROR))
            ->values();
    }

    /**
     * @param  (Closure(string, RuntimeException): void)|null  $on_file_skipped
     * @return array<int, non-falsy-string>
     */
    private function getDefinedKeys(string $locale, ?Closure $on_file_skipped): array {
        return $this->get_app_translations_for_locale->handle($locale, $on_file_skipped)
            ->map(fn (Translation $translation): string => $translation->key)
            ->unique()
            ->all();
    }

    /**
     * @param  non-falsy-string  $key
     * @param  array<string>  $locales
     * @param  list<string>  $locales_with_skipped_files
     * @return list<Translation>
     */
    private function detectMissingForKey(string $key, string $file, array $locales, IgnoredKeys $ignored_keys, array $locales_with_skipped_files): array {
        $missing = [];

        foreach ($locales as $locale) {
            if ($ignored_keys->has($key, $locale, AuditType::Missing)) {
                continue;
            }

            if (! $this->hasForLocale($key, $locale, \in_array($locale, $locales_with_skipped_files, true))) {
                $missing[] = new Translation($key, $locale, $file);
            }
        }

        return $missing;
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
     * The values of a dynamic key are unknown, so the keys matching it in some locale are its values: each is missing in the locales where the translator lacks it, like a static key.
     * When no locale defines a matching key, the dynamic key itself is missing in every locale, by its pattern.
     *
     * @param  array<string, array<int, non-falsy-string>>  $defined_keys  The keys defined in the translation files of each locale.
     * @param  list<string>  $locales_with_skipped_files
     * @return list<Translation>
     */
    private function detectMissingForDynamicKey(DynamicTranslationKey $dynamic_key, string $file, array $defined_keys, IgnoredKeys $ignored_keys, array $locales_with_skipped_files): array {
        // array_values, since spreading the locales as keys would pass them as named arguments.
        $keys_defined_in_any_locale = array_unique(array_merge(...array_values($defined_keys)));
        $matching_keys = array_filter($keys_defined_in_any_locale, $dynamic_key->matches(...));
        $missing = [];

        foreach (array_keys($defined_keys) as $locale) {
            if ($ignored_keys->has($dynamic_key->pattern(), $locale, AuditType::Missing)) {
                continue;
            }

            $has_skipped_files = \in_array($locale, $locales_with_skipped_files, true);
            // the translator decides, as for a static key: a key defined as an array, or by the framework, is there, while one it cannot resolve, like a key with dots in an array key, is not.
            $is_missing = fn (string $key): bool => ! $this->hasForLocale($key, $locale, $has_skipped_files);
            $missing_keys = $matching_keys === [] ? [$dynamic_key->pattern()] : array_filter($matching_keys, $is_missing);

            foreach ($missing_keys as $missing_key) {
                if (! $ignored_keys->has($missing_key, $locale, AuditType::Missing)) {
                    $missing[] = new Translation($missing_key, $locale, $file);
                }
            }
        }

        return $missing;
    }
}
