<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Console\Commands\AuditTranslations;
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
 * Encode the missing locales of each key, grouped by file, as the json display format prints them.
 *
 * @param  array<string, array<string, list<string>>>  $missing
 */
function resultJson(array $missing): string {
    return json_encode($missing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
