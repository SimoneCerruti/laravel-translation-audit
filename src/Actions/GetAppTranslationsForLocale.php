<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Closure;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ParseError;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use TranslationAudit\Data\Translation;

use function Safe\file_get_contents;
use function Safe\json_decode;

final class GetAppTranslationsForLocale {
    /**
     * Get the translations defined in the JSON and PHP translation files of the locale, keyed like the translator expects them.
     *
     * @return Collection<int, Translation>
     */
    public function handle(string $locale): Collection {
        $translations = new Collection;
        $json_path = lang_path("{$locale}.json");

        if (is_file($json_path)) {
            $lines = $this->readTranslationFile($json_path, fn (): mixed => json_decode(file_get_contents($json_path), true, flags: JSON_THROW_ON_ERROR));

            $translations->push(...$this->toTranslations(\is_array($lines) ? $lines : [], $locale, $json_path));
        }

        if (! is_dir(lang_path($locale))) {
            return $translations;
        }

        foreach (Finder::create()->files()->in(lang_path($locale))->name('*.php')->sortByName() as $file) {
            $group = str_replace('\\', '/', substr($file->getRelativePathname(), 0, -\strlen('.php')));
            $lines = $this->readTranslationFile($file->getPathname(), fn (): mixed => File::getRequire($file->getPathname()));

            $translations->push(...$this->toTranslations(\is_array($lines) ? Arr::dot($lines, "{$group}.") : [], $locale, $file->getPathname()));
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
     * Read a translation file, naming it in the error when it cannot be read.
     *
     * @param  Closure(): mixed  $read
     */
    private function readTranslationFile(string $path, Closure $read): mixed {
        try {
            return $read();
        } catch (Exception|ParseError $e) {
            throw new RuntimeException("Unable to read {$this->getPathRelativeToBase($path)}: {$e->getMessage()}", $e->getCode(), previous: $e);
        }
    }

    /** The absolute path relative to the project root, with forward slashes on every OS. */
    private function getPathRelativeToBase(string $path): string {
        return Str::after(str_replace('\\', '/', $path), str_replace('\\', '/', base_path()).'/');
    }
}
