<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use TranslationAudit\Data\SaveTarget;
use TranslationAudit\Enums\SaveFormat;

use function Pest\Laravel\travelTo;

it('joins the directory and the name with the extension of the format', function (string $directory): void {
    expect(new SaveTarget(SaveFormat::Json, $directory, 'result')->resolvePath())->toBe('audits'.DIRECTORY_SEPARATOR.'result.json');
})->with([
    'without a trailing separator' => ['audits'],
    'with a trailing slash' => ['audits/'],
    'with a trailing backslash' => ['audits\\'],
]);

it('resolves the date and random patterns of the name', function (): void {
    travelTo(Carbon::create(2026, 9, 30, 18, 30));

    $path = new SaveTarget(SaveFormat::Json, 'audits', 'audit-{now:Y-m-d H:i}-{random:4}')->resolvePath();

    expect(basename($path))->toMatch('/^audit-2026-09-30 18-30-[a-zA-Z0-9]{4}\.json$/');
});

it('leaves the unknown patterns as they are', function (): void {
    expect(new SaveTarget(SaveFormat::Json, 'audits', 'audit-{unknown:x}')->resolvePath())->toBe('audits'.DIRECTORY_SEPARATOR.'audit-{unknown:x}.json');
});
