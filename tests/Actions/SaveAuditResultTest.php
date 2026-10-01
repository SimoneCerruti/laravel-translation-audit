<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use TranslationAudit\Actions\SaveAuditResult;
use TranslationAudit\Data\AuditResult;

use function Pest\Laravel\travelTo;

it('saves the result as pretty printed json and returns the path of the file', function (): void {
    $result = new AuditResult(missingTranslations(['app/Example.php' => ['Hello' => ['it']]]))
        ->withUnused(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]));

    $path = app(SaveAuditResult::class)->handle($result, 'json', base_path('audits/'), 'result');

    expect($path)->toBe(base_path('audits').DIRECTORY_SEPARATOR.'result.json')
        ->and(File::get($path))->toBe(json_encode([
            'missing' => ['app/Example.php' => ['Hello' => ['it']]],
            'unused' => ['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});

it('saves the empty sections as empty objects and omits the unused section when not audited', function (): void {
    $path = app(SaveAuditResult::class)->handle(new AuditResult(missingTranslations([])), 'json', base_path('audits'), 'result');

    expect(File::get($path))->toBe("{\n    \"missing\": {}\n}");
});

it('resolves the date and random patterns of the name', function (): void {
    travelTo(Carbon::create(2026, 9, 30, 18, 30));

    $path = app(SaveAuditResult::class)->handle(new AuditResult(missingTranslations([])), 'json', base_path('audits'), 'audit-{now:Y-m-d H:i}-{random:4}');

    expect(basename($path))->toMatch('/^audit-2026-09-30 18-30-[a-zA-Z0-9]{4}\.json$/');
});

it('fails on an unsupported format', function (): void {
    app(SaveAuditResult::class)->handle(new AuditResult(missingTranslations([])), 'xml', base_path('audits'), 'result');
})->throws(InvalidArgumentException::class, "Unsupported save format 'xml'.");
