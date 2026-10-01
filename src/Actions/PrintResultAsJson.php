<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use stdClass;
use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Console\Commands\AuditTranslations;

/**
 * @phpstan-import-type AuditResult from AuditTranslations
 */
final class PrintResultAsJson {
    /**
     * @param  AuditResult  $result
     */
    public function handle(array $result, OutputInterface $output): void {
        // The empty sections are encoded as objects, like the non-empty ones.
        $result = array_map(fn (array $section): array|stdClass => $section === [] ? new stdClass : $section, $result);

        $output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }
}
