<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Tests\TestCase;

use function Pest\Laravel\artisan;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Create the throwaway lang directory with empty `lang/{locale}.json` files
 * and `lang/{locale}` directories.
 *
 * @param  list<string>  $files
 * @param  list<string>  $directories
 */
function populateLangDir(array $files = [], array $directories = []): void {
    $path = lang_path();

    File::ensureDirectoryExists($path);

    foreach ($directories as $directory) {
        File::ensureDirectoryExists("{$path}/{$directory}");
    }

    foreach ($files as $file) {
        File::put("{$path}/{$file}", '{}');
    }
}

/** Write a file relative to the application base path, creating missing directories. */
function putFile(string $relative_path, string $contents): void {
    $path = base_path($relative_path);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

/** @param  array<string, string>  $translations */
function putJsonTranslations(string $locale, array $translations): void {
    putFile("lang/{$locale}.json", json_encode($translations, JSON_THROW_ON_ERROR));
}

/** Create a symbolic link at the given path pointing to the given target, both relative to the application base path. */
function putLink(string $relative_target, string $relative_link): void {
    $link = base_path($relative_link);

    File::ensureDirectoryExists(dirname($link));
    symlink(base_path($relative_target), $link);
}

/**
 * List the names of the files in a directory relative to the application base path, or none when it is missing.
 *
 * @return list<string>
 */
function getAuditSavedFiles(string $relative_directory = 'audits'): array {
    $directory = base_path($relative_directory);

    return File::isDirectory($directory)
        ? array_map(fn (SplFileInfo $file): string => $file->getFilename(), File::files($directory))
        : [];
}

/** Read the contents of the first audit result saved in the `audits` directory. */
function getFirstAuditSaveFile(): string {
    return File::get(base_path('audits/'.getAuditSavedFiles()[0]));
}

/** Decode the first audit result saved in the `audits` directory, asserting it is valid JSON. */
function getFirstAuditSaveFileJsonContent(): mixed {
    $contents = getFirstAuditSaveFile();

    expect($contents)->toBeJson();

    return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Run the audit printing the result in the json display format, so the expected result can be asserted as a whole.
 *
 * @param  array<string, mixed>  $parameters
 */
function auditAsJson(array $parameters = []): PendingCommand {
    $command = artisan(AuditTranslations::class, ['--display-format' => 'json', ...$parameters]);

    expect($command)->toBeInstanceOf(PendingCommand::class);

    return $command;
}

/**
 * Encode the result as the json display format prints it: the missing locales of each key grouped by file,
 * and the unused keys grouped by locale and translation file only when given.
 *
 * @param  array<string, array<string, list<string>>>  $missing
 * @param  array<string, array<string, array<string, string>>>|null  $unused
 */
function resultJson(array $missing, ?array $unused = null): string {
    $result = ['missing' => $missing === [] ? new stdClass : $missing];

    if ($unused !== null) {
        $result['unused'] = $unused === [] ? new stdClass : $unused;
    }

    return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * Build the used translation keys from the keys used in each file.
 *
 * @param  array<string, list<non-falsy-string>>  $keys
 * @return Collection<int, UsedTranslationKey>
 */
function usedTranslationKeys(array $keys): Collection {
    $used_keys = new Collection;

    foreach ($keys as $file => $values) {
        foreach ($values as $value) {
            $used_keys->push(new UsedTranslationKey($file, $value));
        }
    }

    return $used_keys;
}

/**
 * Build the missing translations from the missing locales of each key grouped by file.
 *
 * @param  array<string, array<string, list<string>>>  $missing
 * @return Collection<int, Translation>
 */
function missingTranslations(array $missing): Collection {
    $translations = new Collection;

    foreach ($missing as $file => $keys) {
        foreach ($keys as $key => $locales) {
            foreach ($locales as $locale) {
                $translations->push(new Translation((string) $key, $locale, $file));
            }
        }
    }

    return $translations;
}

/**
 * Build the unused translations from the unused keys grouped by locale and translation file.
 *
 * @param  array<string, array<string, array<string, string>>>  $unused
 * @return Collection<int, Translation>
 */
function unusedTranslations(array $unused): Collection {
    $translations = new Collection;

    foreach ($unused as $locale => $files) {
        foreach ($files as $file => $keys) {
            foreach ($keys as $key => $value) {
                $translations->push(new Translation((string) $key, $locale, $file, $value));
            }
        }
    }

    return $translations;
}

/**
 * Run the audit on an output that keeps the standard output and the error output apart, like a real console.
 * The error output is decorated only when asked to, as when it is a terminal.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{exit_code: int, output: string, error_output: string}
 */
function auditWithSeparateOutputs(array $parameters = [], bool $decorated_error_output = false): array {
    $error_output = new BufferedOutput(decorated: $decorated_error_output);

    $output = new class($error_output) extends BufferedOutput implements ConsoleOutputInterface {
        public function __construct(private OutputInterface $error_output) {
            parent::__construct();
        }

        public function getErrorOutput(): OutputInterface {
            return $this->error_output;
        }

        public function setErrorOutput(OutputInterface $error): void {
            $this->error_output = $error;
        }

        public function section(): ConsoleSectionOutput {
            throw new LogicException('Sections are not supported.');
        }
    };

    $exit_code = Artisan::call(AuditTranslations::class, $parameters, $output);

    return ['exit_code' => $exit_code, 'output' => $output->fetch(), 'error_output' => $error_output->fetch()];
}
