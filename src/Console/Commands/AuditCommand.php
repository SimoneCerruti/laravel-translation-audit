<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\FindFilesToScan;
use TranslationAudit\Actions\ScanFilesForTranslationKeys;
use TranslationAudit\Data\DisplayMessage;
use TranslationAudit\Data\SharedConfig;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Enums\MessageSeverity;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Results\Contracts\Result;
use TranslationAudit\Support\CommandOptionHelper;

use function Safe\preg_split;

/**
 * A command scanning the app for the translation keys it uses.
 * It resolves the shared config, then the config of the command, failing as invalid on an invalid one,
 * then runs the before hooks, performs the command, prints its result in the display format followed by its summary, runs the after hooks,
 * and exits with the code of the result, failing when a hook fails.
 *
 * @template TResult of Result
 */
abstract class AuditCommand extends Command {
    /** The options overriding the shared config, appended to the signature of the command. */
    private const string SHARED_OPTIONS = <<<'TXT'
            {--follow-links= : Follow symbolic links while looking for the files to scan. Accept true or false, if no value is specified it defaults to true. Overrides the always_follow_links config}
            {--display-format= : The format in which to display the audit result. Supported formats: json, list, table. Overrides the display_format config}
            {--no-progress= : Whether to hide the progress bar while the files are scanned. Accept true or false, if no value is specified it defaults to true. Overrides the disable_progress_bar config}
            {--no-summary= : Whether to hide the result summary. Accept true or false, if no value is specified it defaults to true. Overrides the disable_summary config}
            {--for-agent= : Output only the json result, for the invocation by an agent. Shortcut for --display-format=json --no-progress --no-summary, which it takes precedence over, together with their configs. Accept true or false, if no value is specified it defaults to true. Enabled automatically, even when false, if the command is run by a detected AI agent}
    TXT;

    protected CommandOptionHelper $options_helper;

    protected SharedConfig $shared_config;

    /**
     * The reason why each file skipped by the scan cannot be scanned, keyed by its path relative to the project root.
     *
     * @var array<string, string>
     */
    protected array $skipped_files = [];

    /**
     * The error naming each translation file skipped because it cannot be read, keyed by its path relative to the project root.
     *
     * @var array<string, RuntimeException>
     */
    private array $skipped_translation_files = [];

    public function __construct() {
        $this->signature .= self::SHARED_OPTIONS;

        parent::__construct();
    }

    final public function handle(): int {
        try {
            $this->options_helper = new CommandOptionHelper($this->input, $this);
            $this->shared_config = SharedConfig::fromInput($this->options_helper);
            $this->resolveConfig();

            $this->warnForHeavyPaths();
        } catch (InvalidConfigException|InvalidArgumentException $e) {
            $this->printError($e->getMessage());

            return self::INVALID;
        }

        $this->skipped_translation_files = [];

        try {
            $this->shared_config->hooks->before($this);

            $result = $this->perform();
            $result->print($this->shared_config->display_format, $this->output);
            $this->printSummary($result);

            $this->shared_config->hooks->after($this, $result);

            return $this->exitCode($result);
        } catch (Exception $e) {
            $this->printError($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Resolve the config of the command, and the options overriding it.
     *
     * @throws InvalidConfigException
     */
    abstract protected function resolveConfig(): void;

    /**
     * Perform the command, returning its result.
     *
     * @return TResult
     */
    abstract protected function perform(): Result;

    /**
     * Summarize the result, printed on the error output after it unless the summary is hidden.
     *
     * @param  TResult  $result
     */
    abstract protected function summarize(Result $result): DisplayMessage;

    /**
     * The exit code of the command for the result, successful by default.
     *
     * @param  TResult  $result
     */
    protected function exitCode(Result $result): int {
        return self::SUCCESS;
    }

    /**
     * Find the files to scan and scan them for the translation keys they use, skipping the files that cannot be scanned with a warning, adding the additional keys in the config,
     * expanding the dynamic keys with values in the config, then replacing the dynamic keys covered by the resolvers with the keys they resolve.
     *
     * @return Collection<int, UsedTranslationKey>
     */
    protected function findTranslationKeys(): Collection {
        $files = app(FindFilesToScan::class)->handle($this->shared_config->scan_paths, $this->shared_config->ignore_paths, $this->shared_config->follow_links, $this->shared_config->ignore_links);

        return $this->scanFiles($files)
            ->pipe($this->shared_config->additional_keys->apply(...))
            ->pipe($this->shared_config->dynamic_keys->expand(...))
            ->pipe($this->shared_config->resolvers->apply(...));
    }

    /** Skip the translation file that cannot be read, keeping the error naming it. */
    protected function skipTranslationFile(string $path, RuntimeException $error): void {
        $this->skipped_translation_files[$path] = $error;
    }

    /**
     * Warn for each translation file skipped because it cannot be read, even for an agent, returning the reason why each file skipped by the scan or as a translation file is skipped, keyed by its path.
     *
     * @return array<string, string>
     */
    protected function warnForSkippedTranslationFiles(): array {
        $errors = array_values($this->skipped_translation_files);

        if ($errors !== []) {
            $count = \count($errors);

            $this->warnForSkippedFiles($errors, \sprintf('Skipped %d translation %s that cannot be read: %s translations are not audited.', $count, Str::plural('file', $count), $count === 1 ? 'its' : 'their'));
        }

        return [
            ...$this->skipped_files,
            ...array_map(fn (RuntimeException $error): string => $error->getPrevious()?->getMessage() ?? $error->getMessage(), $this->skipped_translation_files),
        ];
    }

    /** Print the message on the error output, unless the output is for an agent. */
    protected function printMessage(DisplayMessage $message): void {
        if ($this->shared_config->output_for_agent) {
            return;
        }

        $this->output->getErrorStyle()->writeln($this->formatMessage($message));
    }

    /**
     * Print the lines as a warning on the error output, even for an agent.
     *
     * @param  list<string>  $lines
     */
    protected function printWarning(array $lines): void {
        $this->output->getErrorStyle()->writeln($this->formatMessage(new DisplayMessage(implode(PHP_EOL, $lines), MessageSeverity::Warning)));
        $this->output->getErrorStyle()->newLine();
    }

    protected function printError(string $message): void {
        $this->output->getErrorStyle()->writeln("<error>{$message}</error>");
    }

    /**
     * Scan the files for the translation keys they use, then warn for each file skipped because it cannot be scanned, even for an agent.
     *
     * @param  Collection<int, SplFileInfo>  $files
     * @return Collection<int, UsedTranslationKey>
     */
    private function scanFiles(Collection $files): Collection {
        $this->skipped_files = [];

        if ($files->isEmpty()) {
            return new Collection;
        }

        $progress_output = $this->shouldDisableProgressBar() ? new SymfonyStyle($this->input, new NullOutput) : $this->output->getErrorStyle();
        $progress_bar = $progress_output->createProgressBar($files->count());
        $progress_bar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $progress_bar->setMessage($this->getRelativePath($files->first()));
        $progress_bar->start();

        $errors = [];

        $translation_keys = app(ScanFilesForTranslationKeys::class)->handle(
            $files,
            $this->shared_config->translation_calls,
            function (SplFileInfo $file, int $index) use ($files, $progress_bar): void {
                $next_file = $files->get($index + 1);
                $progress_bar->setMessage($next_file ? $this->getRelativePath($next_file) : '');
                $progress_bar->advance();
            },
            function (SplFileInfo $file, RuntimeException $error) use (&$errors): void {
                $this->skipped_files[$this->getRelativePath($file)] = $error->getPrevious()?->getMessage() ?? $error->getMessage();
                $errors[] = $error;
            },
        );

        $progress_bar->finish();
        $progress_output->newLine(2);

        if ($errors !== []) {
            $count = \count($errors);

            $this->warnForSkippedFiles($errors, \sprintf('Skipped %d %s that cannot be scanned: the translations %s are not audited.', $count, Str::plural('file', $count), $count === 1 ? 'it uses' : 'they use'));
        }

        return $translation_keys;
    }

    /**
     * Print the error naming each skipped file followed by the summary as a warning on the error output, even for an agent.
     *
     * @param  non-empty-list<RuntimeException>  $errors
     */
    private function warnForSkippedFiles(array $errors, string $summary): void {
        $lines = array_map(fn (RuntimeException $error): string => $error->getMessage(), $errors);
        $lines[] = $summary;

        $this->printWarning($lines);
    }

    /**
     * Print the summary of the result on the error output, separated from the result when it is not clean, unless the summary is hidden.
     *
     * @param  TResult  $result
     */
    private function printSummary(Result $result): void {
        if ($this->shared_config->disable_summary) {
            return;
        }

        if (! $result->isClean()) {
            $this->output->getErrorStyle()->newLine();
        }

        $this->output->getErrorStyle()->writeln($this->formatMessage($this->summarize($result)));
    }

    /**
     * Escape each line of the message and style it by the severity.
     *
     * @return list<string>
     */
    private function formatMessage(DisplayMessage $message): array {
        $style = $message->severity->value;

        return array_map(fn (string $line): string => "<{$style}>".OutputFormatter::escape($line)."</{$style}>", preg_split('/\R/', $message->text));
    }

    /** Whether to hide the progress bar: when asked to, for an agent, or when the error output is not a terminal. */
    private function shouldDisableProgressBar(): bool {
        return $this->shared_config->disable_progress_bar || ! $this->output->getErrorStyle()->isDecorated();
    }

    /** The file path relative to the project root, with forward slashes on every OS. */
    private function getRelativePath(SplFileInfo $file): string {
        return str_replace('\\', '/', $file->getRelativePathname());
    }

    private function warnForHeavyPaths(): void {
        /** @var list<non-falsy-string> */
        $heavy_paths = [base_path('vendor'), base_path('node_modules'), storage_path()];

        collect($this->shared_config->scan_paths)
            ->map(base_path(...))
            ->intersect($heavy_paths)
            ->each(fn (string $path) => $this->printMessage(new DisplayMessage("The '{$path}' is set for scan. This may cause heavy resource usage and significantly slow down the audit.", MessageSeverity::Warning)));
    }
}
