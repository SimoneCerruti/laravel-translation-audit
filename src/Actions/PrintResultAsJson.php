<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use stdClass;
use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Data\AuditResult;

final class PrintResultAsJson {
    public function handle(AuditResult $result, OutputInterface $output): void {
        $sections = ['missing' => $result->missingByFile()];

        if ($result->unused instanceof Collection) {
            $sections['unused'] = $result->unusedByLocale();
        }

        // turning empty sections into stdClass so they get encoded as empty objects, like the non-empty ones.
        $sections = array_map(fn (Collection $section): Collection|stdClass => $section->isEmpty() ? new stdClass : $section, $sections);

        $output->writeln(json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }
}
