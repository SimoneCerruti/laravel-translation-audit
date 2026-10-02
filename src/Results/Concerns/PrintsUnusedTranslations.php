<?php

declare(strict_types=1);

namespace TranslationAudit\Results\Concerns;

use Illuminate\Support\Collection;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use TranslationAudit\Actions\FormatLocales;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Data\Translation;

use function Safe\preg_split;

/**
 * Print the unused translations, grouped by locale and by translation file, as a list or as a table.
 *
 * @phpstan-import-type UnusedTranslations from AuditTranslations
 */
trait PrintsUnusedTranslations {
    private const string LOCALE_INDENT = '  ';

    private const string TRANSLATION_FILE_INDENT = '    ';

    private const string UNUSED_KEY_INDENT = '      ';

    private const string TRANSLATION_INDENT = '        ';

    /** Below this terminal space the keys are not wrapped, as the lines would be too short to read. */
    private const int MIN_KEY_WIDTH = 20;

    /**
     * The unused translation of each key, grouped by locale and by the path of the translation file defining it.
     *
     * @param  Collection<int, Translation>  $unused
     * @return Collection<array-key, Collection<array-key, Collection<string, string|null>>>
     */
    private function groupUnusedByLocale(Collection $unused): Collection {
        return $unused
            ->groupBy('locale')
            ->map(fn (Collection $translations): Collection => $translations
                ->groupBy('file')
                ->map(fn (Collection $keys): Collection => $keys->mapWithKeys(fn (Translation $translation): array => [$translation->key => $translation->value])));
    }

    /**
     * @param  UnusedTranslations  $unused
     */
    private function printUnusedAsList(array $unused, OutputInterface $output): void {
        $format_locales = app(FormatLocales::class);
        foreach ($unused as $locale => $files) {
            if ($locale !== array_key_first($unused)) {
                $output->writeln('');
            }

            $output->writeln(self::LOCALE_INDENT.'<fg=yellow>'.$format_locales->handle([(string) $locale]).'</>');

            foreach ($files as $file_path => $keys) {
                $output->writeln(self::TRANSLATION_FILE_INDENT.'<options=bold>'.OutputFormatter::escape((string) $file_path).'</>');

                foreach ($keys as $key => $translation) {
                    $output->writeln(self::UNUSED_KEY_INDENT.OutputFormatter::escape($this->wrap((string) $key, self::UNUSED_KEY_INDENT)));
                    $output->writeln(self::TRANSLATION_INDENT.'<fg=gray>'.OutputFormatter::escape($this->wrap($translation, self::TRANSLATION_INDENT)).'</>');
                }
            }
        }
    }

    /**
     * @param  UnusedTranslations  $unused
     */
    private function printUnusedAsTable(array $unused, OutputInterface $output): void {
        $format_locales = app(FormatLocales::class);
        $rows = [];

        foreach ($unused as $locale => $files) {
            if ($rows !== []) {
                $rows[] = new TableSeparator;
            }

            $is_first_locale_row = true;

            foreach ($files as $file_path => $keys) {
                $is_first_file_row = true;

                foreach ($keys as $key => $translation) {
                    $rows[] = [
                        $is_first_locale_row ? $format_locales->handle([(string) $locale]) : '',
                        $is_first_file_row ? OutputFormatter::escape((string) $file_path) : '',
                        OutputFormatter::escape((string) $key),
                        OutputFormatter::escape($translation),
                    ];

                    $is_first_locale_row = false;
                    $is_first_file_row = false;
                }
            }
        }

        new Table($output)
            ->setHeaders(['Locale', 'Translation file', 'Unused key', 'Translation'])
            ->setRows($rows)
            ->render();
    }

    /** Wrap the text to the terminal width, unless the terminal is too narrow, indenting the following lines as the first one. */
    private function wrap(string $text, string $indent): string {
        $width = new Terminal()->getWidth() - mb_strlen($indent);
        $lines = preg_split('/\R/', $text);

        return implode("\n".$indent, array_map(
            fn (string $line): string => $width >= self::MIN_KEY_WIDTH ? wordwrap($line, $width, "\n".$indent) : $line,
            $lines,
        ));
    }
}
