<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\TranslationAuditServiceProvider;

it('merges the package config', function () {
    expect(config('translation-audit.scan_paths'))->toBe(['app/**/*.php', 'resources/views/**/*blade.php']);
    expect(config('translation-audit.ignore_paths'))->toBe([]);
    expect(config('translation-audit.ignore_locales'))->toBe([]);
    expect(config('translation-audit.supported_locales'))->toBe(['auto']);
});

it('registers the artisan command', function () {
    expect(Artisan::all())->toHaveKey('translation:audit')
        ->and(Artisan::all()['translation:audit'])->toBeInstanceOf(AuditTranslations::class);
});

it('runs the artisan command by its name', function () {
    populateLangDir(files: ['en.json']);

    $this->artisan('translation:audit')->assertSuccessful();
});

it('publishes the package resources', function (string $tag, string $source, string $destination) {
    $paths = ServiceProvider::pathsToPublish(TranslationAuditServiceProvider::class, $tag);

    expect($paths)->toHaveCount(1);

    $from = (string) array_key_first($paths);

    expect(realpath($from))->toBe(realpath(__DIR__."/../{$source}"))->toBeFile()
        ->and(str_replace('\\', '/', $paths[$from]))->toEndWith("/{$destination}");
})->with([
    'config' => ['laravel-translation-audit-config', 'config/translation-audit.php', 'config/translation-audit.php'],
    'workflow' => ['laravel-translation-audit-workflow', 'workflows/audit-translations.yml', '.github/workflows/audit-translations.yml'],
]);

it('publishes every package resource with the package tag', function () {
    expect(ServiceProvider::pathsToPublish(TranslationAuditServiceProvider::class, 'laravel-translation-audit'))->toHaveCount(2);
});
