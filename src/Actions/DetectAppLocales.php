<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Exceptions\InvalidConfigException;

final class DetectAppLocales {
    /**
     * A language code of two or three letters, optionally followed by subtags separated by a dash or an underscore,
     * like en, fil, pt_BR, zh-Hant-TW, es-419 or de_informal.
     */
    private const string LOCALE_PATTERN = '[a-z]{2,3}(?:[-_][a-z0-9]{1,8})*';

    /**
     * Detect the locales from the JSON files and the directories at the top level of the lang directory named like a locale,
     * skipping the others, like vendor, base or locales.json, sorted by name.
     *
     * @return non-empty-list<string>
     *
     * @throws InvalidConfigException
     */
    public function handle(): array {
        $lang_path = lang_path();

        throw_unless(is_dir($lang_path), InvalidConfigException::class, 'Unable to autodetect supported locales. The lang folder is missing.');

        $json_locales = collect(Finder::create()->files()->in($lang_path)->depth(0)->name('/^'.self::LOCALE_PATTERN.'\.json$/i'))
            ->map(fn (SplFileInfo $file) => $file->getBasename('.json'));

        $directory_locales = collect(Finder::create()->directories()->in($lang_path)->depth(0)->name('/^'.self::LOCALE_PATTERN.'$/i'))
            ->map(fn (SplFileInfo $directory) => $directory->getFilename());

        $detected_locales = array_values($json_locales->concat($directory_locales)->unique()->sort()->all());

        throw_if($detected_locales === [], InvalidConfigException::class, 'Unable to autodetect locales in the "lang" folder.');

        return $detected_locales;
    }
}
