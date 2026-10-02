<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Results\AuditTranslationsResult;

it('is clean without missing translations when the unused ones are not audited', function (): void {
    expect(new AuditTranslationsResult(new Collection)->isClean())->toBeTrue();
});

it('is clean without missing and unused translations', function (): void {
    expect(new AuditTranslationsResult(new Collection)->withUnused(new Collection)->isClean())->toBeTrue();
});

it('is not clean with missing translations', function (): void {
    expect(new AuditTranslationsResult(missingTranslations(['app/Example.php' => ['Hello' => ['it']]]))->isClean())->toBeFalse();
});

it('is not clean with unused translations', function (): void {
    expect(new AuditTranslationsResult(new Collection)->withUnused(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]))->isClean())->toBeFalse();
});

function printAuditTranslationsResult(AuditTranslationsResult $result, DisplayFormat $format): string {
    $output = new BufferedOutput;

    $result->print($format, $output);

    return $output->fetch();
}

it('prints a clean result only as json', function (DisplayFormat $format, string $printed): void {
    expect(printAuditTranslationsResult(new AuditTranslationsResult(new Collection), $format))->toBe($printed);
})->with([
    'json' => [DisplayFormat::Json, '{"missing":{}}'.PHP_EOL],
    'list' => [DisplayFormat::List, ''],
    'table' => [DisplayFormat::Table, ''],
]);

it('prints the missing translations in the display format', function (DisplayFormat $format): void {
    expect(printAuditTranslationsResult(new AuditTranslationsResult(missingTranslations(['app/Example.php' => ['Hello' => ['it']]])), $format))
        ->toContain('app/Example.php')
        ->toContain('Hello');
})->with(DisplayFormat::cases());
