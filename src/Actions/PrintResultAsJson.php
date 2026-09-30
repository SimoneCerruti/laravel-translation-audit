<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Console\Commands\AuditTranslations;

/**
 * @phpstan-import-type MissingTranslations from AuditTranslations
 */
final class PrintResultAsJson {
    /**
     * @param  MissingTranslations&non-empty-array  $missing
     */
    public function handle(array $missing, OutputInterface $output): void {
        $output->writeln(json_encode($missing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }
}
