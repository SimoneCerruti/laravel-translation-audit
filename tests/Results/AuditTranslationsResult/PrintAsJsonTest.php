<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Results\AuditTranslationsResult;

function printAuditTranslationsResultAsJson(array $missing, ?array $unused = null): string {
    $output = new BufferedOutput;

    ($unused === null ? new AuditTranslationsResult(missingTranslations($missing)) : new AuditTranslationsResult(missingTranslations($missing))->withUnused(unusedTranslations($unused)))->print(DisplayFormat::Json, $output);

    return $output->fetch();
}

it('prints the missing locales of each key grouped by file as json on a single line', function (): void {
    expect(printAuditTranslationsResultAsJson([
        'app/First.php' => ['Hello' => ['it'], 'messages.goodbye' => ['en', 'it']],
        'app/Second.php' => ['Città' => ['en']],
    ]))->toBe('{"missing":{"app/First.php":{"Hello":["it"],"messages.goodbye":["en","it"]},"app/Second.php":{"Città":["en"]}}}'.PHP_EOL);
});

it('prints the unused translations grouped by locale and translation file after the missing ones', function (): void {
    expect(printAuditTranslationsResultAsJson(
        ['app/Example.php' => ['Hello' => ['it']]],
        ['it' => ['lang/it.json' => ['Bye' => 'Ciao'], 'lang/it/messages.php' => ['messages.old' => 'Vecchio']]],
    ))->toBe('{"missing":{"app/Example.php":{"Hello":["it"]}},"unused":{"it":{"lang/it.json":{"Bye":"Ciao"},"lang/it/messages.php":{"messages.old":"Vecchio"}}}}'.PHP_EOL);
});

it('prints the empty sections as empty objects', function (?array $unused, string $json): void {
    expect(printAuditTranslationsResultAsJson([], $unused))->toBe($json.PHP_EOL);
})->with([
    'without unused' => [null, '{"missing":{}}'],
    'with unused' => [[], '{"missing":{},"unused":{}}'],
]);

it('prints the reason why each skipped file cannot be scanned after the other sections, keyed by its path, only when a file is skipped', function (): void {
    $output = new BufferedOutput;

    new AuditTranslationsResult(missingTranslations([]), skipped: ['app/Broken.php' => 'Syntax error, unexpected EOF on line 1'])
        ->withUnused(unusedTranslations([]))
        ->print(DisplayFormat::Json, $output);

    expect($output->fetch())->toBe('{"missing":{},"unused":{},"skipped":{"app/Broken.php":"Syntax error, unexpected EOF on line 1"}}'.PHP_EOL);
});

it('prints the keys and files containing console tags verbatim', function (): void {
    $missing = ['app/<info>.php' => ['<error>Hello</error>' => ['en']]];

    expect(json_decode(printAuditTranslationsResultAsJson($missing), true, flags: JSON_THROW_ON_ERROR))->toBe(['missing' => $missing]);
});
