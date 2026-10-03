<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
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
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys  The keys used in the scanned files.
     * @param  array<string>  $locales
     * @return Collection<int, Translation>
     */
    public function handle(Collection $translation_keys, array $locales, IgnoredKeys $ignored_keys): Collection {
        $missing = new Collection;
        $dynamic_keys = [];
        // the same key used more than once in a file is detected once.
        $unique_keys = $translation_keys->unique(fn (UsedTranslationKey $key): string => json_encode([$key->file, $key->value], JSON_THROW_ON_ERROR));

        foreach ($unique_keys as $key) {
            if ($key->value instanceof DynamicTranslationKey) {
                $dynamic_keys[] = [$key->file, $key->value];

                continue;
            }

            foreach ($locales as $locale) {
                if ($ignored_keys->has($key->value, $locale)) {
                    continue;
                }

                if (! Lang::hasForLocale($key->value, $locale)) {
                    $missing->push(new Translation($key->value, $locale, $key->file));
                }
            }
        }

        if ($dynamic_keys !== []) {
            $missing->push(...$this->detectMissingForDynamicKeys($dynamic_keys, $locales, $ignored_keys));
        }

        // a key used both statically and dynamically in a file is detected once.
        return $missing
            ->unique(fn (Translation $translation): string => json_encode([$translation->key, $translation->locale, $translation->file], JSON_THROW_ON_ERROR))
            ->values();
    }

    /**
     * @param  non-empty-list<array{string, DynamicTranslationKey}>  $dynamic_keys  The dynamic keys with the path of the file using them.
     * @param  array<string>  $locales
     * @return list<Translation>
     */
    private function detectMissingForDynamicKeys(array $dynamic_keys, array $locales, IgnoredKeys $ignored_keys): array {
        $defined_keys = [];

        foreach ($locales as $locale) {
            $defined_keys[$locale] = $this->get_app_translations_for_locale->handle($locale)->pluck('key')->unique()->all();
        }

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
