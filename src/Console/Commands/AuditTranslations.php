<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Glob;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\PrintResultAsJson;
use TranslationAudit\Actions\PrintResultAsList;
use TranslationAudit\Actions\PrintResultAsTable;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\CommandOptionHelper;

use function Safe\file_get_contents;
use function Safe\preg_match;

/**
 * The missing locales of each translation key, grouped by the path of the file using the key.
 *
 * @phpstan-type MissingTranslations array<string, non-empty-array<non-falsy-string, non-empty-list<string>>>
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
    TXT;

    /** @var string */
    protected $description = 'Audit your app for missing or unused translations.';

    private const array SUPPORTED_SAVE_FORMATS = ['json'];

    private const array SUPPORTED_DISPLAY_FORMATS = ['json', 'list', 'table'];

    private const array TRANSLATION_FUNCTIONS = ['__', 'trans', 'trans_choice'];

    private const array LANG_FACADES = [Lang::class, 'Lang'];

    private const array TRANSLATOR_METHODS = ['get', 'string', 'array', 'choice', 'has', 'hasForLocale'];

    /** @var list<non-falsy-string> */
    private array $scan_paths = [];

    /** @var list<non-falsy-string> */
    private array $ignore_paths = [];

    /** @var list<non-falsy-string> */
    private array $ignore_links = [];

    /** @var list<non-falsy-string> */
    private array $ignore_locales = [];

    /** @var list<string> */
    private array $supported_locales = [];

    /** @var array<string, list<non-falsy-string>> */
    private array $translation_keys = [];

    private bool $should_follow_links = false;

    private bool $should_save_result = false;

    /** @var value-of<self::SUPPORTED_SAVE_FORMATS>|null */
    private ?string $save_format = null;

    /** @var non-falsy-string|null */
    private ?string $save_path = null;

    /** @var array<non-empty-string, list<non-empty-string>|null> Ignored keys mapped to their ignored locales, null for all locales. */
    private array $ignore_keys = [];

    /** @var value-of<self::SUPPORTED_DISPLAY_FORMATS> */
    private string $display_format = 'list';

    private CommandOptionHelper $options_helper;

    public function handle(): int {
        try {
            $this->options_helper = new CommandOptionHelper($this->input, $this);

            $this->scan_paths = $this->getScanPaths();
            $this->ignore_paths = $this->getConfigStringList('ignore_paths');
            $this->ignore_links = $this->getConfigStringList('ignore_links');
            $this->ignore_locales = $this->getConfigStringList('ignore_locales');
            $this->supported_locales = $this->getSupportedLocales();
            $this->should_follow_links = $this->options_helper->booleanOrConfig('follow-links', 'translation-audit.always_follow_links', false);
            $this->should_save_result = $this->options_helper->booleanOrConfig('save', 'translation-audit.always_save', false);
            $this->save_format = $this->should_save_result ? $this->getSaveFormat() : null;
            $this->save_path = $this->should_save_result ? $this->getSavePath() : null;
            $this->ignore_keys = $this->getIgnoreKeys();
            $this->display_format = $this->getDisplayFormat();

            $this->warnForHeavyPaths();
        } catch (InvalidConfigException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        try {
            return $this->audit();
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function audit(): int {
        $this->scanFiles($this->getFilesToAudit());

        $missing = $this->detectMissingTranslations();

        if ($this->should_save_result) {
            $this->saveResult($missing);
        }

        if ($missing === []) {
            $this->info('No missing translations found.');

            return self::SUCCESS;
        }

        $this->printAuditResult($missing);
        $this->newLine();
        $this->printResultSummary($missing);

        return self::FAILURE;
    }

    /** @param  Collection<int, SplFileInfo>  $files */
    private function scanFiles(Collection $files): void {
        if ($files->isEmpty()) {
            return;
        }

        $progress_bar = $this->output->createProgressBar($files->count());
        $progress_bar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $progress_bar->setMessage($this->getRelativePath($files->first()));
        $progress_bar->start();

        foreach ($files as $index => $file) {
            $relative_path = $this->getRelativePath($file);

            try {
                $this->translation_keys[$relative_path] = $this->findTranslationKeysInFile($file);
            } catch (Exception $e) {
                $this->newLine(2);

                throw new RuntimeException("Unable to scan {$relative_path}: {$e->getMessage()}", $e->getCode(), previous: $e);
            }

            $next_file = $files->get($index + 1);
            $progress_bar->setMessage($next_file ? $this->getRelativePath($next_file) : '');
            $progress_bar->advance();
        }

        $progress_bar->finish();
        $this->newLine(2);
    }

    /** @return MissingTranslations */
    private function detectMissingTranslations(): array {
        $missing = [];
        $locales = array_diff($this->supported_locales, $this->ignore_locales);

        foreach ($this->translation_keys as $file_path => $keys) {
            foreach (array_unique($keys) as $key) {
                foreach ($locales as $locale) {
                    if ($this->isIgnoredKey($key, $locale)) {
                        continue;
                    }

                    if (! Lang::hasForLocale($key, $locale)) {
                        $missing[$file_path][$key][] = $locale;
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * @param  MissingTranslations&non-empty-array  $missing
     */
    private function printAuditResult(array $missing): void {
        match ($this->display_format) {
            'list' => app(PrintResultAsList::class)->handle($missing, $this->output),
            'json' => app(PrintResultAsJson::class)->handle($missing, $this->output),
            'table' => app(PrintResultAsTable::class)->handle($missing, $this->output),
        };
    }

    /**
     * @param  MissingTranslations&non-empty-array  $missing
     */
    private function printResultSummary(array $missing): void {
        $keys_count = array_sum(array_map(count(...), $missing));
        $files_count = \count($missing);

        $this->error(\sprintf(
            'Found %d %s with missing translations in %d %s.',
            $keys_count,
            Str::plural('key', $keys_count),
            $files_count,
            Str::plural('file', $files_count),
        ));
    }

    /** @return Collection<int, SplFileInfo> */
    private function getFilesToAudit(): Collection {
        $finder = Finder::create()->files()->in(base_path());

        if ($this->should_follow_links) {
            $finder->followLinks()->filter($this->isNotIgnoredLink(...), prune: true);
        }

        foreach ($this->scan_paths as $scan_path) {
            $finder->path(Glob::toRegex($scan_path));
        }

        foreach ($this->ignore_paths as $ignore_path) {
            $finder->notPath(Glob::toRegex($ignore_path));
        }

        return collect($finder->sortByName())->values();
    }

    private function isNotIgnoredLink(SplFileInfo $file): bool {
        if (! $file->isLink()) {
            return true;
        }

        $relative_path = $this->getRelativePath($file);

        return array_all($this->ignore_links, fn (string $ignore_link): bool => preg_match(Glob::toRegex($ignore_link), $relative_path) !== 1);
    }

    /** The file path relative to the project root, with forward slashes on every OS. */
    private function getRelativePath(SplFileInfo $file): string {
        return str_replace('\\', '/', $file->getRelativePathname());
    }

    /** @return list<non-falsy-string> */
    private function findTranslationKeysInFile(SplFileInfo $file): array {
        $parser = (new ParserFactory)->createForHostVersion();

        $content = file_get_contents($file->getPathname());

        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $content = Blade::compileString($content);
        }

        $statements = new NodeTraverser(new NameResolver)->traverse($parser->parse($content) ?? []);
        $node_finder = new NodeFinder;

        $function_calls = array_filter(
            $node_finder->findInstanceOf($statements, FuncCall::class),
            fn (FuncCall $call): bool => $call->name instanceof Name
                && $call->args !== []
                && \in_array($call->name->toString(), self::TRANSLATION_FUNCTIONS, true),
        );

        $static_calls = array_filter(
            $node_finder->findInstanceOf($statements, StaticCall::class),
            fn (StaticCall $call): bool => $call->class instanceof Name
                && \in_array($call->class->toString(), self::LANG_FACADES, true)
                && $this->isTranslatorMethod($call->name),
        );

        $method_calls = array_filter(
            $node_finder->findInstanceOf($statements, MethodCall::class),
            fn (MethodCall $call): bool => $this->isTranslatorInstance($call->var)
                && $this->isTranslatorMethod($call->name),
        );

        return array_values(
            collect([...$function_calls, ...$static_calls, ...$method_calls])
                ->values()
                ->map($this->getTranslationKeyFromCall(...))
                ->filter()
                ->all(),
        );
    }

    private function getTranslationKeyFromCall(FuncCall|StaticCall|MethodCall $call): ?string {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $first_argument = $call->getArgs()[0] ?? null;

        if (! $first_argument?->value instanceof String_ || $first_argument->value->value === '') {
            return null;
        }

        return $first_argument->value->value;
    }

    private function isTranslatorMethod(Identifier|Expr $name): bool {
        return $name instanceof Identifier && \in_array($name->toString(), self::TRANSLATOR_METHODS, true);
    }

    /** Whether the expression returns the translator: `trans()` without arguments, or `app('translator')`. */
    private function isTranslatorInstance(Expr $expr): bool {
        if (! $expr instanceof FuncCall || ! $expr->name instanceof Name || $expr->isFirstClassCallable()) {
            return false;
        }

        $arguments = $expr->getArgs();

        return match ($expr->name->toString()) {
            'trans' => $arguments === [],
            'app' => isset($arguments[0]) && $arguments[0]->value instanceof String_ && $arguments[0]->value->value === 'translator',
            default => false,
        };
    }

    /**
     * @return array<non-empty-string, list<non-empty-string>|null>
     *
     * @throws InvalidConfigException
     */
    private function getIgnoreKeys(): array {
        try {
            $values = config()->array('translation-audit.ignore_keys');
        } catch (InvalidArgumentException) {
            throw new InvalidConfigException('The "ignore_keys" config must be an array.');
        }

        $ignore_keys = [];

        foreach ($values as $key => $value) {
            if (\is_int($key) && \is_string($value) && $value) {
                $ignore_keys[$value] = null;

                continue;
            }

            if (! \is_string($key) || ! $key || ! \is_array($value) || ! array_is_list($value)) {
                throw new InvalidConfigException('The "ignore_keys" config must contain only keys, or keys mapped to a list of locales.');
            }

            foreach ($value as $locale) {
                if (! \is_string($locale) || ! $locale) {
                    throw new InvalidConfigException("The locales of the \"{$key}\" key in the \"ignore_keys\" config must be non-empty strings.");
                }
            }

            if (! \array_key_exists($key, $ignore_keys)) {
                $ignore_keys[$key] = $value;
            }
        }

        return $ignore_keys;
    }

    private function isIgnoredKey(string $key, string $locale): bool {
        if (! \array_key_exists($key, $this->ignore_keys)) {
            return false;
        }

        $locales = $this->ignore_keys[$key];

        return $locales === null || \in_array($locale, $locales, true);
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
            return $this->detectSupportedLocalesFromFilesystem();
        }

        return $supported_locales_config;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfigException
     */
    private function detectSupportedLocalesFromFilesystem(): array {
        $lang_path = lang_path();

        throw_unless(is_dir($lang_path), InvalidConfigException::class, 'Unable to autodetect supported locales. The lang folder is missing.');

        $json_locales = collect(Finder::create()->files()->in($lang_path)->depth(0)->name('*.json'))
            ->map(fn (SplFileInfo $file) => $file->getBasename('.json'));

        $directory_locales = collect(Finder::create()->directories()->in($lang_path)->depth(0)->notName('vendor'))
            ->map(fn (SplFileInfo $directory) => $directory->getFilename());

        $detected_locales = array_values($json_locales->concat($directory_locales)->unique()->sort()->all());

        throw_if($detected_locales === [], InvalidConfigException::class, 'Unable to autodetect locales in the "lang" folder.');

        return $detected_locales;
    }

    private function warnForHeavyPaths(): void {
        /** @var list<non-falsy-string> */
        $heavy_paths = [base_path('vendor'), base_path('node_modules'), storage_path()];

        collect($this->scan_paths)
            ->map(base_path(...))
            ->intersect($heavy_paths)
            ->each(fn (string $path) => $this->warn("The '{$path}' is set for scan. This may cause heavy resource usage and significantly slow down the audit."));
    }

    /**
     * @param  MissingTranslations  $missing
     */
    private function saveResult(array $missing): void {
        $content = match ($this->save_format) {
            'json' => collect($missing)->toJson(JSON_PRETTY_PRINT),
            default => throw new InvalidConfigException("Invalid save format '".($this->save_format ?? 'NULL')."'. Supported formats: ".implode(', ', self::SUPPORTED_SAVE_FORMATS)),
        };

        File::ensureDirectoryExists(\dirname($this->save_path));
        File::put($this->save_path, $content);

        $this->info("Audit result saved: {$this->save_path}");
    }

    /**
     * @return value-of<self::SUPPORTED_SAVE_FORMATS>
     *
     * @throws InvalidConfigException
     */
    private function getSaveFormat(): string {
        $format = $this->options_helper->nonEmptyStringOrConfig('save-format', 'translation-audit.save_format');

        throw_unless(\in_array($format, self::SUPPORTED_SAVE_FORMATS), InvalidConfigException::class, "Invalid save format '{$format}'. Supported formats: ".implode(', ', self::SUPPORTED_SAVE_FORMATS));

        return $format;
    }

    /**
     * @return non-falsy-string
     */
    private function getSavePath(): string {
        $path = rtrim($this->options_helper->nonEmptyStringOrConfig('save-path', 'translation-audit.save_path'), '/\\');

        $name = $this->resolveSaveName($this->options_helper->nonEmptyStringOrConfig('save-name', 'translation-audit.save_name'));

        return $path.DIRECTORY_SEPARATOR."{$name}.{$this->save_format}";
    }

    private function resolveSaveName(string $name): string {
        return Str::replaceMatches('/\{(\w+)(?::([^}]*))?\}/', fn (array $m): string => match ($m[1]) {
            'now' => Carbon::now()->format(str_replace(['\\', '/'], '-', $m[2] ?? 'Y-m-d')),
            'random' => Str::random((int) (($n = $m[2] ?? 8) < 0 ? 8 : $n)),
            default => $m[0],
        }, $name);
    }

    /**
     * @return value-of<self::SUPPORTED_DISPLAY_FORMATS>
     *
     * @throws InvalidConfigException
     */
    private function getDisplayFormat(): string {
        $format = $this->options_helper->nonEmptyStringOrConfig('display-format', 'translation-audit.display_format');

        throw_unless(\in_array($format, self::SUPPORTED_DISPLAY_FORMATS), InvalidConfigException::class, "Invalid display format '{$format}'. Supported formats: ".implode(', ', self::SUPPORTED_DISPLAY_FORMATS));

        return $format;
    }
}
