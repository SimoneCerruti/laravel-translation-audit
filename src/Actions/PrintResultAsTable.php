<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Data\AuditResult;

/**
 * @phpstan-import-type MissingTranslations from AuditTranslations
 * @phpstan-import-type UnusedTranslations from AuditTranslations
 */
final readonly class PrintResultAsTable {
    public function __construct(private FormatLocales $format_locales) {}

    /**
     * Print the missing translations, preceded by a heading and followed by the unused ones when they are audited.
     */
    public function handle(AuditResult $result, OutputInterface $output): void {
        if (! $result->unused instanceof Collection) {
            $this->printMissing($result->missingByFile()->toArray(), $output);

            return;
        }

        if ($result->missing->isNotEmpty()) {
            $output->writeln('<options=bold>Missing translations</>');
            $output->writeln('');
            $this->printMissing($result->missingByFile()->toArray(), $output);
        }

        if ($result->unused->isNotEmpty()) {
            if ($result->missing->isNotEmpty()) {
                $output->writeln('');
            }

            $output->writeln('<options=bold>Unused translations</>');
            $output->writeln('');
            $this->printUnused($result->unusedByLocale()->toArray(), $output);
        }
    }

    /**
     * @param  MissingTranslations  $missing
     */
    private function printMissing(array $missing, OutputInterface $output): void {
        if ($missing === []) {
            return;
        }

        $rows = [];

        foreach ($missing as $file_path => $keys) {
            if ($rows !== []) {
                $rows[] = new TableSeparator;
            }

            $is_first_row = true;

            foreach ($keys as $key => $locales) {
                $rows[] = [
                    $is_first_row ? OutputFormatter::escape($file_path) : '',
                    OutputFormatter::escape((string) $key),
                    $this->format_locales->handle($locales),
                ];

                $is_first_row = false;
            }
        }

        new Table($output)
            ->setHeaders(['File', 'Key', 'Missing locales'])
            ->setRows($rows)
            ->render();
    }

    /**
     * @param  UnusedTranslations  $unused
     */
    private function printUnused(array $unused, OutputInterface $output): void {
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
                        $is_first_locale_row ? $this->format_locales->handle([(string) $locale]) : '',
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
}
