<?php

declare(strict_types=1);

use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\FindFilesToScan;

/**
 * @param  array<string>  $scan_paths
 * @param  array<string>  $ignore_paths
 * @param  array<string>  $ignore_links
 * @return list<string>
 */
function findFilesToScan(array $scan_paths, array $ignore_paths = [], bool $follow_links = false, array $ignore_links = []): array {
    return app(FindFilesToScan::class)->handle($scan_paths, $ignore_paths, $follow_links, $ignore_links)
        ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getRelativePathname()))
        ->all();
}

it('returns the files matching the scan paths sorted by name', function (): void {
    putFile('app/B.php', '<?php');
    putFile('app/A.php', '<?php');
    putFile('resources/views/welcome.blade.php', '');
    putFile('routes/web.php', '<?php');

    expect(findFilesToScan(['app/**/*.php', 'resources/views/**/*.blade.php']))
        ->toBe(['app/A.php', 'app/B.php', 'resources/views/welcome.blade.php']);
});

it('skips the files matching the ignored paths', function (): void {
    putFile('app/Example.php', '<?php');
    putFile('app/Legacy/Old.php', '<?php');

    expect(findFilesToScan(['app/**/*.php'], ['app/Legacy/**']))->toBe(['app/Example.php']);
});

it('does not follow the symbolic links unless asked to', function (bool $follow_links, array $files): void {
    putFile('shared/Example.php', '<?php');
    putLink('shared', 'app/Shared');

    expect(findFilesToScan(['app/**/*.php'], follow_links: $follow_links))->toBe($files);
})->with([
    'not followed' => [false, []],
    'followed' => [true, ['app/Shared/Example.php']],
]);

it('does not follow the symbolic links matching the ignored links', function (): void {
    putFile('shared/Example.php', '<?php');
    putLink('shared', 'app/Shared');
    putLink('shared', 'app/Legacy');

    expect(findFilesToScan(['app/**/*.php'], follow_links: true, ignore_links: ['app/Legacy']))->toBe(['app/Shared/Example.php']);
});
