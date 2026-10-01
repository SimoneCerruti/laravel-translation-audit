<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

final class DetectMissingTranslations {
    /**
     * Detect the translations missing in each locale for the keys used in each file.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys  The keys used in the scanned files.
     * @param  array<string>  $locales
     * @return Collection<int, Translation>
     */
    public function handle(Collection $translation_keys, array $locales, IgnoredKeys $ignored_keys): Collection {
        $missing = new Collection;
        // the same key used more than once in a file is detected once.
        $unique_keys = $translation_keys->unique(fn (UsedTranslationKey $key): string => json_encode([$key->file, $key->value], JSON_THROW_ON_ERROR));

        foreach ($unique_keys as $key) {
            foreach ($locales as $locale) {
                if ($ignored_keys->has($key->value, $locale)) {
                    continue;
                }

                if (! Lang::hasForLocale($key->value, $locale)) {
                    $missing->push(new Translation($key->value, $locale, $key->file));
                }
            }
        }

        return $missing;
    }
}
