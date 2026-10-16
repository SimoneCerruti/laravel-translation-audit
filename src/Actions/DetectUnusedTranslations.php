<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Closure;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Finder\Glob;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Enums\AuditType;
use TranslationAudit\Support\IgnoredKeys;
use TranslationAudit\Support\UsedKeys;

use function Safe\preg_match;

/** @internal */
final readonly class DetectUnusedTranslations {
    public function __construct(private GetAppTranslationsForLocale $get_app_translations_for_locale) {}

    /**
     * Detect the translations defined in the translation files of each locale but used in none of the scanned files.
     * A translation matching a dynamic key is used, since the key can take its value at runtime.
     * A translation of a PHP file is also used when a key used in a file or a dynamic key matches one of its parents,
     * e.g. `messages.errors.title` for `__('messages.errors')`, since the translator returns the array of its children.
     * A translation of a PHP file is also used by a key with leading slashes, e.g. `/admin/form.title` for `admin/form.title`,
     * since the translator appends the group to the path of the locale folder, so it loads the same file.
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
        $json_used_keys = UsedKeys::fromTranslationKeys($translation_keys);
        $php_used_keys = $json_used_keys->withoutLeadingSlashes();
        $unused = new Collection;

        foreach ($locales as $locale) {
            foreach ($this->get_app_translations_for_locale->handle($locale, $on_file_skipped) as $translation) {
                if ($this->isIgnored($translation, $ignore_paths, $ignored_keys) || $this->isUsed($translation, $json_used_keys, $php_used_keys)) {
                    continue;
                }

                $unused->push($translation);
            }
        }

        return $unused;
    }

    /** @param  array<string>  $ignore_paths */
    private function isIgnored(Translation $translation, array $ignore_paths, IgnoredKeys $ignored_keys): bool {
        if ($ignored_keys->has($translation->key, $translation->locale, AuditType::Unused)) {
            return true;
        }

        return array_any($ignore_paths, fn (string $ignore_path): bool => preg_match(Glob::toRegex($ignore_path), $translation->file) === 1);
    }

    /**
     * The keys of the JSON files are flat and loaded as they are, so they are matched literally.
     * A key of a PHP file is also used through its parents, which return the array of their children,
     * and through the keys with leading slashes, which load the same file.
     */
    private function isUsed(Translation $translation, UsedKeys $json_used_keys, UsedKeys $php_used_keys): bool {
        if (str_ends_with($translation->file, '.json')) {
            return $json_used_keys->has($translation->key);
        }

        return array_any($this->withParentKeys($translation->key), $php_used_keys->has(...));
    }

    /**
     * The key followed by its parents, e.g. `messages.errors.title`, `messages.errors` and `messages`.
     *
     * @return non-empty-list<string>
     */
    private function withParentKeys(string $key): array {
        $segments = explode('.', $key);
        $keys = [];

        for ($length = \count($segments); $length > 0; $length--) {
            $keys[] = implode('.', \array_slice($segments, 0, $length));
        }

        return $keys;
    }
}
