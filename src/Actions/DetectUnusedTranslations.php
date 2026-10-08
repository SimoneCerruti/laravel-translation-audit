<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Closure;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Finder\Glob;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

use function Safe\preg_match;

final readonly class DetectUnusedTranslations {
    public function __construct(private GetAppTranslationsForLocale $get_app_translations_for_locale) {}

    /**
     * Detect the translations defined in the translation files of each locale but used in none of the scanned files.
     * A translation matching a dynamic key is used, since the key can take its value at runtime.
     * A translation of a PHP file is also used when a key used in a file or a dynamic key matches one of its parents,
     * e.g. `messages.errors.title` for `__('messages.errors')`, since the translator returns the array of its children.
     * A translation file that cannot be read fails, unless a callback for the skipped files is given: then it is skipped, without any of its translations.
     *
     * @param  Collection<int, UsedTranslationKey>  $translation_keys  The keys used in the scanned files.
     * @param  array<string>  $locales
     * @param  array<string>  $ignore_paths  The glob patterns of the translation files to skip, relative to the project root.
     * @param  (Closure(string, RuntimeException): void)|null  $on_file_skipped  Called for each translation file that cannot be read, with its path relative to the project root and the error naming it.
     * @return Collection<int, Translation>
     *
     * @throws RuntimeException Naming the translation file that cannot be read, without a callback for the skipped files.
     */
    public function handle(Collection $translation_keys, array $locales, array $ignore_paths, IgnoredKeys $ignored_keys, ?Closure $on_file_skipped = null): Collection {
        $used_keys = $translation_keys->filter(fn (UsedTranslationKey $key): bool => \is_string($key->value))->keyBy('value');
        $dynamic_keys = $translation_keys
            ->map(fn (UsedTranslationKey $key): string|DynamicTranslationKey => $key->value)
            ->filter(fn (string|DynamicTranslationKey $key): bool => $key instanceof DynamicTranslationKey)
            ->keyBy(fn (DynamicTranslationKey $key): string => json_encode($key->segments, JSON_THROW_ON_ERROR));

        return new Collection($locales)
            ->flatMap(fn (string $locale): Collection => $this->get_app_translations_for_locale->handle($locale, $on_file_skipped))
            ->reject(fn (Translation $translation): bool => $this->isIgnoredPath($translation->file, $ignore_paths)
                || $this->isUsed($translation, $used_keys, $dynamic_keys)
                || $ignored_keys->has($translation->key, $translation->locale))
            ->values();
    }

    /**
     * @param  Collection<string, UsedTranslationKey>  $used_keys
     * @param  Collection<array-key, DynamicTranslationKey>  $dynamic_keys
     */
    private function isUsed(Translation $translation, Collection $used_keys, Collection $dynamic_keys): bool {
        $keys = [$translation->key];

        // the keys of the JSON files are flat, so only those of the PHP files have parents.
        if (! str_ends_with($translation->file, '.json')) {
            $segments = explode('.', $translation->key);

            for ($length = \count($segments) - 1; $length > 0; $length--) {
                $keys[] = implode('.', \array_slice($segments, 0, $length));
            }
        }

        return array_any($keys, fn (string $key): bool => $used_keys->has($key)
            || $dynamic_keys->contains(fn (DynamicTranslationKey $dynamic_key): bool => $dynamic_key->matches($key)));
    }

    /** @param  array<string>  $ignore_paths */
    private function isIgnoredPath(string $relative_path, array $ignore_paths): bool {
        return array_any($ignore_paths, fn (string $ignore_path): bool => preg_match(Glob::toRegex($ignore_path), $relative_path) === 1);
    }
}
