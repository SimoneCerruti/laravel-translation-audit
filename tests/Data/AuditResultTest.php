<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use TranslationAudit\Data\AuditResult;

it('is clean without missing translations when the unused ones are not audited', function (): void {
    expect(new AuditResult(new Collection)->isClean())->toBeTrue();
});

it('is clean without missing and unused translations', function (): void {
    expect(new AuditResult(new Collection)->withUnused(new Collection)->isClean())->toBeTrue();
});

it('is not clean with missing translations', function (): void {
    expect(new AuditResult(missingTranslations(['app/Example.php' => ['Hello' => ['it']]]))->isClean())->toBeFalse();
});

it('is not clean with unused translations', function (): void {
    expect(new AuditResult(new Collection)->withUnused(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]))->isClean())->toBeFalse();
});
