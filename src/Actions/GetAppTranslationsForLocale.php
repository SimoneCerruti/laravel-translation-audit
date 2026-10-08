<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;
use TranslationAudit\Data\Translation;
use UnexpectedValueException;

use function Safe\file_get_contents;
use function Safe\json_decode;

final class GetAppTranslationsForLocale {
    /**
     * Get the translations defined in the JSON and PHP translation files of the locale, keyed like the translator expects them.
     * A translation file that cannot be read, or not holding an array, fails, unless a callback for the skipped files is given: then it is skipped, without any of its translations.
     *
     * @param  (Closure(string, RuntimeException): void)|null  $on_file_skipped  Called for each translation file that cannot be read, with its path relative to the project root and the error naming it.
     * @return Collection<int, Translation>
     *
     * @throws RuntimeException Naming the translation file that cannot be read, without a callback for the skipped files.
     */
    public function handle(string $locale, ?Closure $on_file_skipped = null): Collection {
        $translations = new Collection;
        $json_path = lang_path("{$locale}.json");

        if (is_file($json_path)) {
            $lines = $this->readTranslationFile($json_path, fn (): mixed => json_decode(file_get_contents($json_path), true, flags: JSON_THROW_ON_ERROR), $on_file_skipped);

            $translations->push(...$this->toTranslations($lines ?? [], $locale, $json_path));
        }

        if (! is_dir(lang_path($locale))) {
            return $translations;
        }

        foreach (Finder::create()->files()->in(lang_path($locale))->name('*.php')->sortByName() as $file) {
            $group = str_replace('\\', '/', substr($file->getRelativePathname(), 0, -\strlen('.php')));
            $lines = $this->readTranslationFile($file->getPathname(), fn (): mixed => File::getRequire($file->getPathname()), $on_file_skipped);

            $translations->push(...$this->toTranslations(Arr::dot($lines ?? [], "{$group}."), $locale, $file->getPathname()));
        }

        return $translations;
    }

    /**
     * Turn the string lines of a translation file into translations, skipping the falsy keys.
     *
     * @param  array<array-key, mixed>  $lines
     * @return list<Translation>
     */
    private function toTranslations(array $lines, string $locale, string $path): array {
        $file = $this->getPathRelativeToBase($path);
        $translations = [];

        foreach ($lines as $key => $value) {
            $key = (string) $key;

            if ($key === '' || $key === '0' || ! \is_string($value)) {
                continue;
            }

            $translations[] = new Translation($key, $locale, $file, $value);
        }

        return $translations;
    }

    /**
     * Read a translation file, naming it in the error when it cannot be read or does not hold an array, like an empty PHP file, which the translator cannot load either.
     * The error is passed to the callback for the skipped files when given, returning null.
     *
     * @param  Closure(): mixed  $read
     * @param  (Closure(string, RuntimeException): void)|null  $on_file_skipped
     * @return array<array-key, mixed>|null
     *
     * @throws RuntimeException Without a callback for the skipped files.
     */
    private function readTranslationFile(string $path, Closure $read, ?Closure $on_file_skipped): ?array {
        $relative_path = $this->getPathRelativeToBase($path);

        try {
            $lines = $read();

            if (! \is_array($lines)) {
                throw new UnexpectedValueException(\sprintf('The file does not hold an array of translations, %s given.', get_debug_type($lines)));
            }

            return $lines;
        } catch (Throwable $e) {
            $error = new RuntimeException("Unable to read {$relative_path}: {$e->getMessage()}", (int) $e->getCode(), previous: $e);

            if (! $on_file_skipped instanceof Closure) {
                throw $error;
            }

            $on_file_skipped($relative_path, $error);

            return null;
        }
    }

    /** The absolute path relative to the project root, with forward slashes on every OS. */
    private function getPathRelativeToBase(string $path): string {
        return Str::after(str_replace('\\', '/', $path), str_replace('\\', '/', base_path()).'/');
    }
}
