<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Console\Commands\AuditTranslations;

/**
 * @phpstan-import-type MissingTranslations from AuditTranslations
 */
final readonly class PrintResultAsTable {
    public function __construct(private FormatLocales $format_locales) {}

    /**
     * @param  MissingTranslations&non-empty-array  $missing
     */
    public function handle(array $missing, OutputInterface $output): void {
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
}
