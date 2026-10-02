<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\FindFilesToScan;
use TranslationAudit\Actions\ScanFilesForTranslationKeys;
use TranslationAudit\Data\SharedConfig;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\CommandOptionHelper;

/**
 * A command scanning the app for the translation keys it uses.
 * It resolves the shared config, then the config of the command, failing as invalid on an invalid one, and then performs the command.
 */
abstract class AuditCommand extends Command {
    /** The options overriding the shared config, to append to the signature of the command. */
    protected const string SHARED_OPTIONS = <<<'TXT'
            {--follow-links= : Follow symbolic links while looking for the files to scan. Accept true or false, if no value is specified it defaults to true. Overrides the always_follow_links config}
            {--display-format= : The format in which to display the audit result. Supported formats: json, list, table. Overrides the display_format config}
            {--no-progress= : Whether to hide the progress bar while the files are scanned. Accept true or false, if no value is specified it defaults to true. Overrides the disable_progress_bar config}
            {--no-summary= : Whether to hide the result summary. Accept true or false, if no value is specified it defaults to true. Overrides the disable_summary config}
            {--for-agent= : Output only the json result, for the invocation by an agent. Shortcut for --display-format=json --no-progress --no-summary, which it takes precedence over, together with their configs. Accept true or false, if no value is specified it defaults to true. Enabled automatically, even when false, if the command is run by a detected AI agent}
    TXT;

    protected CommandOptionHelper $options_helper;

    protected SharedConfig $shared_config;

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

        try {
            return $this->perform();
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

    /** Perform the command, returning its exit code. */
    abstract protected function perform(): int;

    /**
     * Find the files to scan and scan them for the translation keys they use.
     *
     * @return Collection<int, UsedTranslationKey>
     */
    protected function findTranslationKeys(): Collection {
        $files = app(FindFilesToScan::class)->handle($this->shared_config->scan_paths, $this->shared_config->ignore_paths, $this->shared_config->follow_links, $this->shared_config->ignore_links);

        return $this->scanFiles($files);
    }

    /** Print the message on the error output, unless the output is for an agent. */
    protected function printMessage(string $message, string $style): void {
        if ($this->shared_config->output_for_agent) {
            return;
        }

        $this->output->getErrorStyle()->writeln("<{$style}>{$message}</{$style}>");
    }

    protected function printError(string $message): void {
        $this->output->getErrorStyle()->writeln("<error>{$message}</error>");
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

        $progress_output = $this->shouldDisableProgressBar() ? new SymfonyStyle($this->input, new NullOutput) : $this->output->getErrorStyle();
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
            ->each(fn (string $path) => $this->printMessage("The '{$path}' is set for scan. This may cause heavy resource usage and significantly slow down the audit.", 'comment'));
    }
}
