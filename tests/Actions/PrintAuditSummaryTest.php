<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Actions\PrintAuditSummary;
use TranslationAudit\Data\AuditTranslationsResult;

function printAuditSummary(AuditTranslationsResult $result): string {
    $output = new BufferedOutput;

    app(PrintAuditSummary::class)->handle($result, $output);

    return str_replace(PHP_EOL, "\n", $output->fetch());
}

it('prints the count of the keys with missing translations and of the files using them', function (): void {
    $result = new AuditTranslationsResult(missingTranslations([
        'app/First.php' => ['Hello' => ['en', 'it'], 'Bye' => ['it']],
        'app/Second.php' => ['Hello' => ['it']],
    ]));

    expect(printAuditSummary($result))->toBe("Found 3 keys with missing translations in 2 files.\n");
});

it('prints the count of the unused keys and of the translation files defining them', function (): void {
    $result = new AuditTranslationsResult(new Collection)->withUnused(unusedTranslations([
        'en' => ['lang/en.json' => ['Bye' => 'Bye']],
        'it' => ['lang/it.json' => ['Bye' => 'Arrivederci'], 'lang/it/messages.php' => ['messages.old' => 'Vecchio']],
    ]));

    expect(printAuditSummary($result))->toBe("Found 3 unused keys in 3 translation files.\n");
});

it('prints both counts in the singular', function (): void {
    $result = new AuditTranslationsResult(missingTranslations(['app/Example.php' => ['Hello' => ['it']]]))
        ->withUnused(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]));

    expect(printAuditSummary($result))->toBe(<<<'TXT'
        Found 1 key with missing translations in 1 file.
        Found 1 unused key in 1 translation file.

        TXT);
});

it('prints nothing for a clean result', function (): void {
    expect(printAuditSummary(new AuditTranslationsResult(new Collection)->withUnused(new Collection)))->toBeEmpty();
});
