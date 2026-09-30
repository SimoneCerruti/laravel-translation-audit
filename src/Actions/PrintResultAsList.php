<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use TranslationAudit\Console\Commands\AuditTranslations;

/**
 * @phpstan-import-type MissingTranslations from AuditTranslations
 */
final readonly class PrintResultAsList {
    private const string FILE_INDENT = '  ';

    private const string KEY_INDENT = '    ';

    private const string LOCALES_GAP = '  ';

    /** Below this terminal space the keys are not wrapped, as the lines would be too short to read. */
    private const int MIN_KEY_WIDTH = 20;

    public function __construct(private FormatLocales $format_locales) {}

    /**
     * @param  MissingTranslations&non-empty-array  $missing
     */
    public function handle(array $missing, OutputInterface $output): void {
        $locales_width = max(array_map(
            fn (array $keys): int => max(array_map(fn (array $locales): int => mb_strlen($this->format_locales->handle($locales)), $keys)),
            $missing,
        ));
        $key_indent = str_repeat(' ', mb_strlen(self::KEY_INDENT) + $locales_width + mb_strlen(self::LOCALES_GAP));
        $key_width = new Terminal()->getWidth() - mb_strlen($key_indent);

        foreach ($missing as $file_path => $keys) {
            if ($file_path !== array_key_first($missing)) {
                $output->writeln('');
            }

            $output->writeln(self::FILE_INDENT.'<options=bold>'.OutputFormatter::escape($file_path).'</>');

            foreach ($keys as $key => $locales) {
                $key = (string) $key;

                if ($key_width >= self::MIN_KEY_WIDTH) {
                    $key = wordwrap($key, $key_width, "\n".$key_indent);
                }

                $output->writeln(
                    self::KEY_INDENT.'<fg=yellow>'.mb_str_pad($this->format_locales->handle($locales), $locales_width).'</>'.self::LOCALES_GAP.OutputFormatter::escape($key),
                );
            }
        }
    }
}
