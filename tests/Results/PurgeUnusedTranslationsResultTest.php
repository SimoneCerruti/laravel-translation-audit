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

it('is clean without unused translations', function (): void {
    expect(new PurgeUnusedTranslationsResult(new Collection)->isClean())->toBeTrue()
        ->and(new PurgeUnusedTranslationsResult(unusedTranslations(['it' => ['lang/it.json' => ['Bye' => 'Arrivederci']]]))->isClean())->toBeFalse();
});

it('prints a clean result only as json', function (DisplayFormat $format, string $printed): void {
    expect(printPurgeUnusedTranslationsResult(new PurgeUnusedTranslationsResult(new Collection), $format))->toBe($printed);
})->with([
    'json' => [DisplayFormat::Json, "{\"unused\":{}}\n"],
    'list' => [DisplayFormat::List, ''],
    'table' => [DisplayFormat::Table, ''],
]);

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
