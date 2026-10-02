<?php

declare(strict_types=1);

namespace TranslationAudit\Results;

use Closure;
use Illuminate\Support\Collection;
use stdClass;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Actions\FormatLocales;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Data\Translation;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Results\Concerns\PrintsUnusedTranslations;
use TranslationAudit\Results\Contracts\Result;

/**
 * @phpstan-import-type MissingTranslations from AuditTranslations
 * @phpstan-import-type UnusedTranslations from AuditTranslations
 */
readonly class AuditTranslationsResult implements Result {
    use PrintsUnusedTranslations;

    private const string FILE_INDENT = '  ';

    private const string KEY_INDENT = '    ';

    private const string LOCALES_GAP = '  ';

    /**
     * @param  Collection<int, Translation>  $missing
     * @param  Collection<int, Translation>|null  $unused  The unused translations, null when they are not audited.
     */
    public function __construct(
        public Collection $missing,
        public ?Collection $unused = null,
    ) {}

    /**
     * @param  Collection<int, Translation>  $unused
     */
    public function withUnused(Collection $unused): self {
        return new self($this->missing, $unused);
    }

    /** Whether no translation is missing and, when they are audited, none is unused. */
    public function isClean(): bool {
        return $this->missing->isEmpty() && ($this->unused ?? new Collection)->isEmpty();
    }

    /**
     * The missing locales of each translation key, grouped by the path of the file using the key.
     *
     * @return Collection<array-key, Collection<array-key, Collection<int, string>>>
     */
    public function missingByFile(): Collection {
        return $this->missing
            ->groupBy('file')
            ->map(fn (Collection $translations): Collection => $translations
                ->groupBy('key')
                ->map(fn (Collection $locales): Collection => $locales->map(fn (Translation $translation): string => $translation->locale)));
    }

    /**
     * The unused translation of each key, grouped by locale and by the path of the translation file defining it.
     *
     * @return Collection<array-key, Collection<array-key, Collection<string, string|null>>>
     */
    public function unusedByLocale(): Collection {
        return $this->groupUnusedByLocale($this->unused ?? new Collection);
    }

    public function print(DisplayFormat $format, OutputInterface $output): void {
        match ($format) {
            DisplayFormat::Json => $this->printJson($output),
            DisplayFormat::List => $this->printList($output),
            DisplayFormat::Table => $this->printTable($output),
        };
    }

    private function printJson(OutputInterface $output): void {
        $sections = ['missing' => $this->missingByFile()];

        if ($this->unused instanceof Collection) {
            $sections['unused'] = $this->unusedByLocale();
        }

        // turning empty sections into stdClass so they get encoded as empty objects, like the non-empty ones.
        $sections = array_map(fn (Collection $section): Collection|stdClass => $section->isEmpty() ? new stdClass : $section, $sections);

        $output->writeln(json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    /** Print the result as a list, unless it is clean. */
    private function printList(OutputInterface $output): void {
        if (! $this->isClean()) {
            $this->printSections($output, $this->printMissingAsList(...), $this->printUnusedAsList(...));
        }
    }

    /** Print the result as tables, unless it is clean. */
    private function printTable(OutputInterface $output): void {
        if (! $this->isClean()) {
            $this->printSections($output, $this->printMissingAsTable(...), $this->printUnusedAsTable(...));
        }
    }

    /**
     * Print the missing translations, preceded by a heading and followed by the unused ones when they are audited.
     *
     * @param  Closure(MissingTranslations, OutputInterface): void  $print_missing
     * @param  Closure(UnusedTranslations, OutputInterface): void  $print_unused
     */
    private function printSections(OutputInterface $output, Closure $print_missing, Closure $print_unused): void {
        if (! $this->unused instanceof Collection) {
            $print_missing($this->missingByFile()->toArray(), $output);

            return;
        }

        if ($this->missing->isNotEmpty()) {
            $output->writeln('<options=bold>Missing translations</>');
            $output->writeln('');
            $print_missing($this->missingByFile()->toArray(), $output);
        }

        if ($this->unused->isNotEmpty()) {
            if ($this->missing->isNotEmpty()) {
                $output->writeln('');
            }

            $output->writeln('<options=bold>Unused translations</>');
            $output->writeln('');
            $print_unused($this->unusedByLocale()->toArray(), $output);
        }
    }

    /**
     * @param  MissingTranslations  $missing
     */
    private function printMissingAsList(array $missing, OutputInterface $output): void {
        if ($missing === []) {
            return;
        }

        $format_locales = app(FormatLocales::class);
        $locales_width = max(array_map(
            fn (array $keys): int => max(array_map(fn (array $locales): int => mb_strlen($format_locales->handle($locales)), $keys)),
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
                    self::KEY_INDENT.'<fg=yellow>'.mb_str_pad($format_locales->handle($locales), $locales_width).'</>'.self::LOCALES_GAP.OutputFormatter::escape($this->wrap((string) $key, $key_indent)),
                );
            }
        }
    }

    /**
     * @param  MissingTranslations  $missing
     */
    private function printMissingAsTable(array $missing, OutputInterface $output): void {
        if ($missing === []) {
            return;
        }

        $format_locales = app(FormatLocales::class);
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
                    $format_locales->handle($locales),
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
