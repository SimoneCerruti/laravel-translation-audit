<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\AgentDetector\AgentDetector;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\BuildAuditResult;
use TranslationAudit\Actions\DetectAppLocales;
use TranslationAudit\Actions\FindFilesToScan;
use TranslationAudit\Actions\PrintAuditSummary;
use TranslationAudit\Actions\SaveAuditResult;
use TranslationAudit\Actions\ScanFilesForTranslationKeys;
use TranslationAudit\Data\AuditResult;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Enums\SaveFormat;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\CommandOptionHelper;
use TranslationAudit\Support\IgnoredKeys;

/**
 * The missing locales of each translation key, grouped by the path of the file using the key.
 *
 * @phpstan-type MissingTranslations array<string, non-empty-array<non-falsy-string, non-empty-list<string>>>
 * @phpstan-type UnusedTranslations array<string, non-empty-array<string, non-empty-array<non-falsy-string, string>>>
 */
class AuditTranslations extends Command {
    /** @var string */
    protected $signature = <<<'TXT'
        translation:audit
            {--follow-links= : Follow symbolic links while looking for the files to scan. Accept true or false, if no value is specified it defaults to true. Overrides the always_follow_links config}
            {--save= : Persist the audit result. Accept true or false, if no value is specified it defaults to true. Overrides the always_save config}
            {--save-format= : The format in which to save the audit result. Supported formats: json. Overrides the save_format config}
            {--save-path= : The path in which to save the audit result. Overrides the save_path config}
            {--save-name= : The name of the file to save the audit result to, without the extension. It supports the following patterns, which have to be wrapped in curly braces: - now:<format>: inserts the current date in the specified format; - random:<length>: inserts random alphanumeric characters (a-zA-Z0-9). Overrides the save_name config}
            {--display-format= : The format in which to display the audit result. Supported formats: json, list, table. Overrides the display_format config}
            {--no-progress= : Whether to hide the progress bar while the files are scanned. Accept true or false, if no value is specified it defaults to true. Overrides the disable_progress_bar config}
            {--no-summary= : Whether to hide the result summary. Accept true or false, if no value is specified it defaults to true. Overrides the disable_summary config}
            {--for-agent= : Output only the json result, for the invocation by an agent. Shortcut for --display-format=json --no-progress --no-summary, which it takes precedence over, together with their configs. Accept true or false, if no value is specified it defaults to true. Enabled automatically, even when false, if the command is run by a detected AI agent}
            {--unused= : Also audit for unused translations, defined in the translation files but used in none of the scanned files. Accept true or false, if no value is specified it defaults to true. Overrides the audit_unused config}
    TXT;

    /** @var string */
    protected $description = 'Audit your app for missing or unused translations.';

    /** @var list<non-falsy-string> */
    private array $scan_paths = [];

    /** @var list<non-falsy-string> */
    private array $ignore_paths = [];

    /** @var list<non-falsy-string> */
    private array $ignore_links = [];

    /** @var list<non-falsy-string> */
    private array $ignore_locales = [];

    /** @var list<non-falsy-string> */
    private array $unused_ignore_paths = [];

    /** @var list<string> */
    private array $supported_locales = [];

    private bool $should_follow_links = false;

    private bool $should_save_result = false;

    private bool $should_disable_progress_bar = false;

    private bool $should_disable_summary = false;

    private bool $should_output_for_agent = false;

    private bool $should_audit_for_unused_translations = false;

    private ?SaveFormat $save_format = null;

    private ?string $save_directory = null;

    private ?string $save_name = null;

    private IgnoredKeys $ignore_keys;

    private DisplayFormat $display_format = DisplayFormat::List;

    private CommandOptionHelper $options_helper;

    public function handle(): int {
        try {
            $this->options_helper = new CommandOptionHelper($this->input, $this);

            $this->scan_paths = $this->getScanPaths();
            $this->ignore_paths = $this->getConfigStringList('ignore_paths');
            $this->ignore_links = $this->getConfigStringList('ignore_links');
            $this->ignore_locales = $this->getConfigStringList('ignore_locales');
            $this->unused_ignore_paths = $this->getConfigStringList('unused_ignore_paths');
            $this->supported_locales = $this->getSupportedLocales();
            $this->should_follow_links = $this->options_helper->booleanOrConfig('follow-links', 'translation-audit.always_follow_links', false);
            $this->should_save_result = $this->options_helper->booleanOrConfig('save', 'translation-audit.always_save', false);
            $this->save_format = $this->should_save_result ? $this->getSaveFormat() : null;
            $this->save_directory = $this->should_save_result ? $this->options_helper->nonEmptyStringOrConfig('save-path', 'translation-audit.save_path') : null;
            $this->save_name = $this->should_save_result ? $this->options_helper->nonEmptyStringOrConfig('save-name', 'translation-audit.save_name') : null;
            $this->ignore_keys = $this->getIgnoreKeys();
            $this->should_output_for_agent = AgentDetector::detect()->isAgent || $this->options_helper->boolean('for-agent', false);
            $this->display_format = $this->getDisplayFormat();
            $this->should_disable_progress_bar = $this->shouldDisableProgressBar();
            $this->should_disable_summary = $this->shouldDisableSummary();
            $this->should_audit_for_unused_translations = $this->options_helper->booleanOrConfig('unused', 'translation-audit.audit_unused', false);

            $this->warnForHeavyPaths();
        } catch (InvalidConfigException|InvalidArgumentException $e) {
            $this->printError($e->getMessage());

            return self::INVALID;
        }

        try {
            return $this->audit();
        } catch (Exception $e) {
            $this->printError($e->getMessage());

            return self::FAILURE;
        }
    }

    private function audit(): int {
        $files = app(FindFilesToScan::class)->handle($this->scan_paths, $this->ignore_paths, $this->should_follow_links, $this->ignore_links);
        $translation_keys = $this->scanFiles($files);

        $locales = array_diff($this->supported_locales, $this->ignore_locales);
        $result = app(BuildAuditResult::class)->handle($translation_keys, $locales, $this->ignore_keys, $this->should_audit_for_unused_translations, $this->unused_ignore_paths);

        if ($this->should_save_result) {
            $path = app(SaveAuditResult::class)->handle($result, $this->save_format, $this->save_directory, $this->save_name);

            $this->printMessage("Audit result saved: {$path}", 'info');
        }

        if ($result->isClean()) {
            if ($this->display_format === DisplayFormat::Json) {
                $this->printAuditResult($result);
            }

            $this->printMessage($this->should_audit_for_unused_translations ? 'No missing or unused translations found.' : 'No missing translations found.', 'info');

            return self::SUCCESS;
        }

        $this->printAuditResult($result);

        if (! $this->should_disable_summary) {
            $this->output->getErrorStyle()->newLine();
            app(PrintAuditSummary::class)->handle($result, $this->output->getErrorStyle());
        }

        return self::FAILURE;
    }

    /**
     * Scan the files for the translation keys they use.
     *
     * @param  Collection<int, SplFileInfo>  $files
     * @return Collection<int, UsedTranslationKey>
     */
    private function scanFiles(Collection $files): Collection {
        if ($files->isEmpty()) {
            return new Collection;
        }

        $progress_output = $this->should_disable_progress_bar ? new SymfonyStyle($this->input, new NullOutput) : $this->output->getErrorStyle();
        $progress_bar = $progress_output->createProgressBar($files->count());
        $progress_bar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $progress_bar->setMessage($this->getRelativePath($files->first()));
        $progress_bar->start();

        try {
            $translation_keys = app(ScanFilesForTranslationKeys::class)->handle($files, function (SplFileInfo $file, int $index) use ($files, $progress_bar): void {
                $next_file = $files->get($index + 1);
                $progress_bar->setMessage($next_file ? $this->getRelativePath($next_file) : '');
                $progress_bar->advance();
            });
        } catch (RuntimeException $e) {
            $progress_output->newLine(2);

            throw $e;
        }

        $progress_bar->finish();
        $progress_output->newLine(2);

        return $translation_keys;
    }

    private function printAuditResult(AuditResult $result): void {
        $this->display_format->getPrinter()->handle($result, $this->output);
    }

    /** The file path relative to the project root, with forward slashes on every OS. */
    private function getRelativePath(SplFileInfo $file): string {
        return str_replace('\\', '/', $file->getRelativePathname());
    }

    /**
     * @throws InvalidConfigException
     */
    private function getIgnoreKeys(): IgnoredKeys {
        try {
            $values = config()->array('translation-audit.ignore_keys');
        } catch (InvalidArgumentException) {
            throw new InvalidConfigException('The "ignore_keys" config must be an array.');
        }

        return IgnoredKeys::fromConfig($values);
    }

    /**
     * @return list<non-falsy-string>
     *
     * @throws InvalidConfigException
     */
    private function getConfigStringList(string $key): array {
        try {
            $values = config()->array("translation-audit.{$key}");
        } catch (InvalidArgumentException) {
            throw new InvalidConfigException("The \"{$key}\" config must be an array.");
        }

        $strings = [];

        foreach ($values as $value) {
            if (! \is_string($value) || ! $value) {
                throw new InvalidConfigException("The \"{$key}\" config must contain only non-empty strings.");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @return list<non-falsy-string>
     *
     * @throws InvalidConfigException
     */
    private function getScanPaths(): array {
        $scan_paths = $this->getConfigStringList('scan_paths');

        throw_if($scan_paths === [], InvalidConfigException::class, 'Specify which paths to scan in the "scan_paths" config.');

        return $scan_paths;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfigException
     */
    private function getSupportedLocales(): array {
        $supported_locales_config = $this->getConfigStringList('supported_locales');

        throw_if($supported_locales_config === [], InvalidConfigException::class, 'The "supported_locales" config must be an array listing the app supported locales. Use "auto" as the first value of the array to autodetect locales from the "lang" folder');

        $is_auto = $supported_locales_config[0] === 'auto';

        if ($is_auto) {
            return app(DetectAppLocales::class)->handle();
        }

        return $supported_locales_config;
    }

    private function warnForHeavyPaths(): void {
        /** @var list<non-falsy-string> */
        $heavy_paths = [base_path('vendor'), base_path('node_modules'), storage_path()];

        collect($this->scan_paths)
            ->map(base_path(...))
            ->intersect($heavy_paths)
            ->each(fn (string $path) => $this->printMessage("The '{$path}' is set for scan. This may cause heavy resource usage and significantly slow down the audit.", 'comment'));
    }

    /**
     * The config accepts a SaveFormat case or its value.
     *
     * @throws InvalidConfigException
     */
    private function getSaveFormat(): SaveFormat {
        $config = config('translation-audit.save_format');
        $format = $this->options_helper->nonEmptyString('save-format', $config instanceof SaveFormat ? $config->value : config()->string('translation-audit.save_format'));
        $save_format = SaveFormat::tryFrom($format);

        throw_unless($save_format instanceof SaveFormat, InvalidConfigException::class, "Invalid save format '{$format}'. Supported formats: ".implode(', ', array_column(SaveFormat::cases(), 'value')));

        return $save_format;
    }

    /**
     * The config accepts a DisplayFormat case or its value.
     *
     * @throws InvalidConfigException
     */
    private function getDisplayFormat(): DisplayFormat {
        if ($this->should_output_for_agent) {
            return DisplayFormat::Json;
        }

        $config = config('translation-audit.display_format');
        $format = $this->options_helper->nonEmptyString('display-format', $config instanceof DisplayFormat ? $config->value : config()->string('translation-audit.display_format'));
        $display_format = DisplayFormat::tryFrom($format);

        throw_unless($display_format instanceof DisplayFormat, InvalidConfigException::class, "Invalid display format '{$format}'. Supported formats: ".implode(', ', array_column(DisplayFormat::cases(), 'value')));

        return $display_format;
    }

    /** Whether to hide the progress bar: when asked to, for an agent, or when the error output is not a terminal. */
    private function shouldDisableProgressBar(): bool {
        $is_disabled = $this->options_helper->booleanOrConfig('no-progress', 'translation-audit.disable_progress_bar', false);

        return $is_disabled || $this->should_output_for_agent || ! $this->output->getErrorStyle()->isDecorated();
    }

    private function shouldDisableSummary(): bool {
        $is_disabled = $this->options_helper->booleanOrConfig('no-summary', 'translation-audit.disable_summary', false);

        return $is_disabled || $this->should_output_for_agent;
    }

    private function printMessage(string $message, string $style): void {
        if ($this->should_output_for_agent) {
            return;
        }

        $this->output->getErrorStyle()->writeln("<{$style}>{$message}</{$style}>");
    }

    private function printError(string $message): void {
        $this->output->getErrorStyle()->writeln("<error>{$message}</error>");
    }
}
