<?php

declare(strict_types=1);

namespace TranslationAudit\Results;

use Illuminate\Support\Collection;
use stdClass;
use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Data\Translation;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Results\Concerns\PrintsUnusedTranslations;
use TranslationAudit\Results\Contracts\Result;

readonly class PurgeUnusedTranslationsResult implements Result {
    use PrintsUnusedTranslations;

    /**
     * @param  Collection<int, Translation>  $unused  The unused translations purged, or the ones that would be purged on a dry run.
     * @param  array<string, string>  $skipped  The reason why each file skipped by the scan cannot be scanned, keyed by its path.
     * @param  array<string, array<string, array<string, string>>>  $not_purged  The reason why each unused translation cannot be purged, keyed by its key and grouped by locale and translation file.
     */
    public function __construct(
        public Collection $unused,
        public array $skipped = [],
        public array $not_purged = [],
    ) {}

    /** Whether no translation is unused, purged or not. */
    public function isClean(): bool {
        return $this->unused->isEmpty() && $this->not_purged === [];
    }

    /** The number of unused translations that cannot be purged. */
    public function countNotPurged(): int {
        return array_sum(array_map(fn (array $files): int => array_sum(array_map(\count(...), $files)), $this->not_purged));
    }

    public function print(DisplayFormat $format, OutputInterface $output): void {
        match ($format) {
            DisplayFormat::Json => $this->printJson($output),
            DisplayFormat::List => $this->printList($output),
            DisplayFormat::Table => $this->printTable($output),
        };
    }

    private function printJson(OutputInterface $output): void {
        $unused = $this->groupUnusedByLocale($this->unused);

        // turning an empty section into stdClass so it gets encoded as an empty object, like a non-empty one.
        $sections = ['unused' => $unused->isEmpty() ? new stdClass : $unused];

        if ($this->not_purged !== []) {
            $sections['not_purged'] = $this->not_purged;
        }

        if ($this->skipped !== []) {
            $sections['skipped'] = $this->skipped;
        }

        $output->writeln(json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    /** Print the unused translations as a list, unless there are none. */
    private function printList(OutputInterface $output): void {
        if ($this->unused->isNotEmpty()) {
            $this->printUnusedAsList($this->groupUnusedByLocale($this->unused)->toArray(), $output);
        }
    }

    /** Print the unused translations as a table, unless there are none. */
    private function printTable(OutputInterface $output): void {
        if ($this->unused->isNotEmpty()) {
            $this->printUnusedAsTable($this->groupUnusedByLocale($this->unused)->toArray(), $output);
        }
    }
}
