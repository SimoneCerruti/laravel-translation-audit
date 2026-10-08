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
     * @param  Collection<int, Translation>  $unused  The unused translations, purged unless the command is a dry run.
     * @param  array<string, string>  $skipped  The reason why each file skipped by the scan cannot be scanned, keyed by its path.
     */
    public function __construct(
        public Collection $unused,
        public array $skipped = [],
    ) {}

    /** Whether no translation is unused. */
    public function isClean(): bool {
        return $this->unused->isEmpty();
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

        if ($this->skipped !== []) {
            $sections['skipped'] = $this->skipped;
        }

        $output->writeln(json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    /** Print the result as a list, unless it is clean. */
    private function printList(OutputInterface $output): void {
        if (! $this->isClean()) {
            $this->printUnusedAsList($this->groupUnusedByLocale($this->unused)->toArray(), $output);
        }
    }

    /** Print the result as a table, unless it is clean. */
    private function printTable(OutputInterface $output): void {
        if (! $this->isClean()) {
            $this->printUnusedAsTable($this->groupUnusedByLocale($this->unused)->toArray(), $output);
        }
    }
}
