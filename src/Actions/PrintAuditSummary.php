<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Data\AuditResult;

final class PrintAuditSummary {
    /**
     * Print how many keys have missing translations and in how many files, then how many keys are unused and in how many translation files.
     */
    public function handle(AuditResult $result, OutputInterface $output): void {
        if ($result->missing->isNotEmpty()) {
            $missing = $result->missingByFile();
            $keys_count = $missing->sum(fn (Collection $keys): int => $keys->count());
            $files_count = $missing->count();

            $output->writeln(\sprintf(
                '<error>Found %d %s with missing translations in %d %s.</error>',
                $keys_count,
                Str::plural('key', $keys_count),
                $files_count,
                Str::plural('file', $files_count),
            ));
        }

        if ($result->unused instanceof Collection && $result->unused->isNotEmpty()) {
            $keys_count = $result->unused->count();
            $files_count = $result->unused->pluck('file')->unique()->count();

            $output->writeln(\sprintf(
                '<error>Found %d unused %s in %d translation %s.</error>',
                $keys_count,
                Str::plural('key', $keys_count),
                $files_count,
                Str::plural('file', $files_count),
            ));
        }
    }
}
