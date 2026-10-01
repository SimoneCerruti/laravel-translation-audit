<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use TranslationAudit\Console\Commands\AuditTranslations;

use function Safe\preg_split;

/**
 * @phpstan-import-type AuditResult from AuditTranslations
 * @phpstan-import-type MissingTranslations from AuditTranslations
 * @phpstan-import-type UnusedTranslations from AuditTranslations
 */
final readonly class PrintResultAsList {
    private const string FILE_INDENT = '  ';

    private const string KEY_INDENT = '    ';

    private const string LOCALES_GAP = '  ';

    private const string LOCALE_INDENT = '  ';

    private const string TRANSLATION_FILE_INDENT = '    ';

    private const string UNUSED_KEY_INDENT = '      ';

    private const string TRANSLATION_INDENT = '        ';

    /** Below this terminal space the keys are not wrapped, as the lines would be too short to read. */
    private const int MIN_KEY_WIDTH = 20;

    public function __construct(private FormatLocales $format_locales) {}

    /**
     * Print the missing translations, preceded by a heading and followed by the unused ones when they are audited.
     *
     * @param  AuditResult  $result
     */
    public function handle(array $result, OutputInterface $output): void {
        if (! \array_key_exists('unused', $result)) {
            $this->printMissing($result['missing'], $output);

            return;
        }

        if ($result['missing'] !== []) {
            $output->writeln('<options=bold>Missing translations</>');
            $output->writeln('');
            $this->printMissing($result['missing'], $output);
        }

        if ($result['unused'] !== []) {
            if ($result['missing'] !== []) {
                $output->writeln('');
            }

            $output->writeln('<options=bold>Unused translations</>');
            $output->writeln('');
            $this->printUnused($result['unused'], $output);
        }
    }

    /**
     * @param  MissingTranslations  $missing
     */
    private function printMissing(array $missing, OutputInterface $output): void {
        if ($missing === []) {
            return;
        }

        $locales_width = max(array_map(
            fn (array $keys): int => max(array_map(fn (array $locales): int => mb_strlen($this->format_locales->handle($locales)), $keys)),
            $missing,
        ));
        $key_indent = str_repeat(' ', mb_strlen(self::KEY_INDENT) + $locales_width + mb_strlen(self::LOCALES_GAP));

        foreach ($missing as $file_path => $keys) {
            if ($file_path !== array_key_first($missing)) {
                $output->writeln('');
            }

            $output->writeln(self::FILE_INDENT.'<options=bold>'.OutputFormatter::escape($file_path).'</>');

            foreach ($keys as $key => $locales) {
                $output->writeln(
                    self::KEY_INDENT.'<fg=yellow>'.mb_str_pad($this->format_locales->handle($locales), $locales_width).'</>'.self::LOCALES_GAP.OutputFormatter::escape($this->wrap((string) $key, $key_indent)),
                );
            }
        }
    }

    /**
     * @param  UnusedTranslations  $unused
     */
    private function printUnused(array $unused, OutputInterface $output): void {
        foreach ($unused as $locale => $files) {
            if ($locale !== array_key_first($unused)) {
                $output->writeln('');
            }

            $output->writeln(self::LOCALE_INDENT.'<fg=yellow>'.$this->format_locales->handle([(string) $locale]).'</>');

            foreach ($files as $file_path => $keys) {
                $output->writeln(self::TRANSLATION_FILE_INDENT.'<options=bold>'.OutputFormatter::escape((string) $file_path).'</>');

                foreach ($keys as $key => $translation) {
                    $output->writeln(self::UNUSED_KEY_INDENT.OutputFormatter::escape($this->wrap((string) $key, self::UNUSED_KEY_INDENT)));
                    $output->writeln(self::TRANSLATION_INDENT.'<fg=gray>'.OutputFormatter::escape($this->wrap($translation, self::TRANSLATION_INDENT)).'</>');
                }
            }
        }
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
