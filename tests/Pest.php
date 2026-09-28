<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use TranslationAudit\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

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
