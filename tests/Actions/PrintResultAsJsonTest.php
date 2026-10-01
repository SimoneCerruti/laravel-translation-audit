<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Actions\PrintResultAsJson;
use TranslationAudit\Data\AuditResult;

function printResultAsJson(array $missing, ?array $unused = null): string {
    $output = new BufferedOutput;

    app(PrintResultAsJson::class)->handle($unused === null ? new AuditResult(missingTranslations($missing)) : new AuditResult(missingTranslations($missing))->withUnused(unusedTranslations($unused)), $output);

    return $output->fetch();
}

it('prints the missing locales of each key grouped by file as json on a single line', function (): void {
    expect(printResultAsJson([
        'app/First.php' => ['Hello' => ['it'], 'messages.goodbye' => ['en', 'it']],
        'app/Second.php' => ['Città' => ['en']],
    ]))->toBe('{"missing":{"app/First.php":{"Hello":["it"],"messages.goodbye":["en","it"]},"app/Second.php":{"Città":["en"]}}}'.PHP_EOL);
});

it('prints the unused translations grouped by locale and translation file after the missing ones', function (): void {
    expect(printResultAsJson(
        ['app/Example.php' => ['Hello' => ['it']]],
        ['it' => ['lang/it.json' => ['Bye' => 'Ciao'], 'lang/it/messages.php' => ['messages.old' => 'Vecchio']]],
    ))->toBe('{"missing":{"app/Example.php":{"Hello":["it"]}},"unused":{"it":{"lang/it.json":{"Bye":"Ciao"},"lang/it/messages.php":{"messages.old":"Vecchio"}}}}'.PHP_EOL);
});

it('prints the empty sections as empty objects', function (?array $unused, string $json): void {
    expect(printResultAsJson([], $unused))->toBe($json.PHP_EOL);
})->with([
    'without unused' => [null, '{"missing":{}}'],
    'with unused' => [[], '{"missing":{},"unused":{}}'],
]);

it('prints the keys and files containing console tags verbatim', function (): void {
    $missing = ['app/<info>.php' => ['<error>Hello</error>' => ['en']]];

    expect(json_decode(printResultAsJson($missing), true, flags: JSON_THROW_ON_ERROR))->toBe(['missing' => $missing]);
});
