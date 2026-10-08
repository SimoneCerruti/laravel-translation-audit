<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use TranslationAudit\Actions\SaveAuditResult;
use TranslationAudit\Data\SaveTarget;
use TranslationAudit\Enums\SaveFormat;
use TranslationAudit\Results\AuditTranslationsResult;

it('saves the result as pretty printed json and returns the path of the file', function (): void {
    $result = new AuditTranslationsResult(missingTranslations(['app/Example.php' => ['Hello' => ['it']]]))
        ->withUnused(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]));

    $path = app(SaveAuditResult::class)->handle($result, new SaveTarget(SaveFormat::Json, base_path('audits/'), 'result'));

    expect($path)->toBe(base_path('audits').DIRECTORY_SEPARATOR.'result.json')
        ->and(File::get($path))->toBe(json_encode([
            'missing' => ['app/Example.php' => ['Hello' => ['it']]],
            'unused' => ['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});

it('saves the reason why each skipped file cannot be scanned, keyed by its path', function (): void {
    $result = new AuditTranslationsResult(missingTranslations([]), skipped: ['app/Broken.php' => 'Syntax error']);

    $path = app(SaveAuditResult::class)->handle($result, new SaveTarget(SaveFormat::Json, base_path('audits'), 'result'));

    expect(json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR))->toBe(['missing' => [], 'skipped' => ['app/Broken.php' => 'Syntax error']]);
});

it('saves the empty sections as empty objects and omits the unused section when not audited', function (): void {
    $path = app(SaveAuditResult::class)->handle(new AuditTranslationsResult(missingTranslations([])), new SaveTarget(SaveFormat::Json, base_path('audits'), 'result'));

    expect(File::get($path))->toBe("{\n    \"missing\": {}\n}");
});
