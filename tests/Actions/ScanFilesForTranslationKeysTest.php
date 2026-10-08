<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\ScanFilesForTranslationKeys;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\TranslationCalls;

/**
 * @param  list<string>  $relative_paths
 * @return Collection<int, SplFileInfo>
 */
function filesToScan(array $relative_paths): Collection {
    return collect($relative_paths)->map(fn (string $relative_path): SplFileInfo => new SplFileInfo(base_path($relative_path), dirname($relative_path), $relative_path));
}

it('returns the translation keys used in each file with its relative path', function (): void {
    putFile('app/First.php', "<?php __('Hello'); __('Bye');");
    putFile('app/Second.php', "<?php __('Hello');");

    $translation_keys = app(ScanFilesForTranslationKeys::class)->handle(filesToScan(['app/First.php', 'app/Second.php']), TranslationCalls::fromConfig([]));

    expect($translation_keys->all())->toEqual([
        new UsedTranslationKey('app/First.php', 'Hello'),
        new UsedTranslationKey('app/First.php', 'Bye'),
        new UsedTranslationKey('app/Second.php', 'Hello'),
    ]);
});

it('calls the callback after each scanned file with the file and its index', function (): void {
    putFile('app/First.php', '<?php');
    putFile('app/Second.php', '<?php');
    $scanned = [];

    app(ScanFilesForTranslationKeys::class)->handle(filesToScan(['app/First.php', 'app/Second.php']), TranslationCalls::fromConfig([]), function (SplFileInfo $file, int $index) use (&$scanned): void {
        $scanned[$index] = $file->getRelativePathname();
    });

    expect($scanned)->toBe([0 => 'app/First.php', 1 => 'app/Second.php']);
});

it('fails naming the file that cannot be scanned, without calling the callback for it', function (): void {
    putFile('app/First.php', '<?php');
    putFile('app/Broken.php', '<?php function (');
    $scanned = [];

    expect(function () use (&$scanned): void {
        app(ScanFilesForTranslationKeys::class)->handle(filesToScan(['app/First.php', 'app/Broken.php']), TranslationCalls::fromConfig([]), function (SplFileInfo $file) use (&$scanned): void {
            $scanned[] = $file->getRelativePathname();
        });
    })->toThrow(RuntimeException::class, 'Unable to scan app/Broken.php: Syntax error')
        ->and($scanned)->toBe(['app/First.php']);
});

it('skips the files that cannot be scanned with the skipped files callback, calling it with the error naming the file', function (): void {
    putFile('app/Broken.php', '<?php function (');
    putFile('resources/views/broken.blade.php', '<x-missing-component />');
    putFile('app/Second.php', "<?php __('Hello');");
    $scanned = [];
    $skipped = [];

    $translation_keys = app(ScanFilesForTranslationKeys::class)->handle(
        filesToScan(['app/Broken.php', 'resources/views/broken.blade.php', 'app/Second.php']),
        TranslationCalls::fromConfig([]),
        function (SplFileInfo $file) use (&$scanned): void {
            $scanned[] = $file->getRelativePathname();
        },
        function (SplFileInfo $file, RuntimeException $error) use (&$skipped): void {
            $skipped[$file->getRelativePathname()] = $error->getMessage();
        },
    );

    expect($translation_keys->all())->toEqual([new UsedTranslationKey('app/Second.php', 'Hello')])
        ->and($scanned)->toBe(['app/Broken.php', 'resources/views/broken.blade.php', 'app/Second.php'])
        ->and(array_keys($skipped))->toBe(['app/Broken.php', 'resources/views/broken.blade.php'])
        ->and($skipped['app/Broken.php'])->toStartWith('Unable to scan app/Broken.php: Syntax error')
        ->and($skipped['resources/views/broken.blade.php'])->toStartWith('Unable to scan resources/views/broken.blade.php: ');
});
