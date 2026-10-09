<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Results\PurgeUnusedTranslationsResult;

function printPurgeUnusedTranslationsResult(PurgeUnusedTranslationsResult $result, DisplayFormat $format): string {
    $output = new BufferedOutput;

    $result->print($format, $output);

    return str_replace(PHP_EOL, "\n", $output->fetch());
}

const NOT_PURGED = ['it' => ['lang/it/messages.php' => ['messages.foo' => 'The file does not return a literal array.', 'messages.bar' => 'The file does not return a literal array.']]];

it('is clean without unused translations, purged or not', function (): void {
    expect(new PurgeUnusedTranslationsResult(new Collection)->isClean())->toBeTrue()
        ->and(new PurgeUnusedTranslationsResult(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]))->isClean())->toBeFalse()
        ->and(new PurgeUnusedTranslationsResult(new Collection, not_purged: NOT_PURGED)->isClean())->toBeFalse();
});

it('counts the unused translations that cannot be purged', function (): void {
    $not_purged = [...NOT_PURGED, 'en' => ['lang/en/messages.php' => ['messages.foo' => 'The key is known only by running the code.']]];

    expect(new PurgeUnusedTranslationsResult(new Collection)->countNotPurged())->toBe(0)
        ->and(new PurgeUnusedTranslationsResult(new Collection, not_purged: $not_purged)->countNotPurged())->toBe(3);
});

it('prints the reason why each unused translation cannot be purged after the unused translations as json, grouped by locale and file', function (): void {
    $result = new PurgeUnusedTranslationsResult(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]), ['app/Broken.php' => 'Syntax error'], NOT_PURGED);

    expect(printPurgeUnusedTranslationsResult($result, DisplayFormat::Json))
        ->toBe('{"unused":{"it":{"lang/it.json":{"Bye":"Arrivederci"}}},"not_purged":{"it":{"lang/it/messages.php":{"messages.foo":"The file does not return a literal array.","messages.bar":"The file does not return a literal array."}}},"skipped":{"app/Broken.php":"Syntax error"}}'."\n");
});

it('prints nothing as a list or a table when no unused translation can be purged', function (DisplayFormat $format): void {
    expect(printPurgeUnusedTranslationsResult(new PurgeUnusedTranslationsResult(new Collection, not_purged: NOT_PURGED), $format))->toBeEmpty();
})->with([DisplayFormat::List, DisplayFormat::Table]);

it('prints a clean result only as json', function (DisplayFormat $format, string $printed): void {
    expect(printPurgeUnusedTranslationsResult(new PurgeUnusedTranslationsResult(new Collection), $format))->toBe($printed);
})->with([
    'json' => [DisplayFormat::Json, "{\"unused\":{}}\n"],
    'list' => [DisplayFormat::List, ''],
    'table' => [DisplayFormat::Table, ''],
]);

it('prints the reason why each skipped file cannot be scanned after the unused translations as json, keyed by its path', function (): void {
    $result = new PurgeUnusedTranslationsResult(new Collection, ['app/Broken.php' => 'Syntax error, unexpected EOF on line 1']);

    expect(printPurgeUnusedTranslationsResult($result, DisplayFormat::Json))
        ->toBe('{"unused":{},"skipped":{"app/Broken.php":"Syntax error, unexpected EOF on line 1"}}'."\n");
});

it('prints the unused translations grouped by locale and file as json', function (): void {
    $result = new PurgeUnusedTranslationsResult(unusedTranslations([
        'en' => ['lang/en/messages.php' => ['messages.bye' => 'Bye']],
        'it' => ['lang/it.json' => ['Ciao' => 'Ciao']],
    ]));

    expect(printPurgeUnusedTranslationsResult($result, DisplayFormat::Json))
        ->toBe('{"unused":{"en":{"lang/en/messages.php":{"messages.bye":"Bye"}},"it":{"lang/it.json":{"Ciao":"Ciao"}}}}'."\n");
});

it('prints the unused translations as a list without heading', function (): void {
    $result = new PurgeUnusedTranslationsResult(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]));

    expect(printPurgeUnusedTranslationsResult($result, DisplayFormat::List))->toBe(<<<'TXT'
          IT
            lang/it.json
              Bye
                Arrivederci

        TXT);
});

it('prints the unused translations as a table without heading', function (): void {
    $result = new PurgeUnusedTranslationsResult(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]));

    expect(printPurgeUnusedTranslationsResult($result, DisplayFormat::Table))
        ->not->toContain('Unused translations')
        ->toContain('| Locale | Translation file | Unused key | Translation |')
        ->toContain('| IT     | lang/it.json     | Bye        | Arrivederci |');
});
