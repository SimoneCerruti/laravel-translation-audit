<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Actions\PrintResultAsTable;

function printResultAsTable(array $missing, ?array $unused = null): string {
    $output = new BufferedOutput;

    app(PrintResultAsTable::class)->handle(['missing' => $missing, ...($unused === null ? [] : ['unused' => $unused])], $output);

    return str_replace(PHP_EOL, "\n", $output->fetch());
}

it('prints the keys in a table with a section per file', function (): void {
    expect(printResultAsTable([
        'app/First.php' => ['Hello' => ['it'], 'messages.goodbye' => ['en', 'it']],
        'app/Second.php' => ['Hello' => ['en']],
    ]))->toBe(<<<'TXT'
        +----------------+------------------+-----------------+
        | File           | Key              | Missing locales |
        +----------------+------------------+-----------------+
        | app/First.php  | Hello            | IT              |
        |                | messages.goodbye | EN, IT          |
        +----------------+------------------+-----------------+
        | app/Second.php | Hello            | EN              |
        +----------------+------------------+-----------------+

        TXT);
});

it('prints the keys and files containing console tags verbatim', function (): void {
    expect(printResultAsTable(['app/<info>.php' => ['<error>Hello</error>' => ['en']]]))
        ->toContain('| app/<info>.php | <error>Hello</error> | EN              |');
});

it('prints numeric keys', function (): void {
    expect(printResultAsTable(['app/Example.php' => [404 => ['en']]]))->toContain('| app/Example.php | 404 | EN              |');
});

it('prints the missing and the unused translations in two tables under their headings', function (): void {
    expect(printResultAsTable(
        ['app/Example.php' => ['Hello' => ['it']]],
        [
            'en' => ['lang/en/messages.php' => ['messages.old' => 'Old']],
            'it' => ['lang/it.json' => ['Bye' => 'Ciao'], 'lang/it/messages.php' => ['messages.old' => 'Vecchio', 'messages.older' => 'Più vecchio']],
        ],
    ))->toBe(<<<'TXT'
        Missing translations

        +-----------------+-------+-----------------+
        | File            | Key   | Missing locales |
        +-----------------+-------+-----------------+
        | app/Example.php | Hello | IT              |
        +-----------------+-------+-----------------+

        Unused translations

        +--------+----------------------+----------------+
        | Locale | Translation file     | Unused key     |
        +--------+----------------------+----------------+
        | EN     | lang/en/messages.php | messages.old   |
        +--------+----------------------+----------------+
        | IT     | lang/it.json         | Bye            |
        |        | lang/it/messages.php | messages.old   |
        |        |                      | messages.older |
        +--------+----------------------+----------------+

        TXT);
});

it('prints only the heading of the non-empty section', function (array $missing, array $unused, string $heading, string $other_heading): void {
    expect(printResultAsTable($missing, $unused))->toStartWith($heading)
        ->not->toContain($other_heading);
})->with([
    'only missing' => [['app/Example.php' => ['Hello' => ['it']]], [], 'Missing translations', 'Unused translations'],
    'only unused' => [[], ['it' => ['lang/it.json' => ['Bye' => 'Ciao']]], 'Unused translations', 'Missing translations'],
]);

it('prints the unused keys and files containing console tags verbatim', function (): void {
    expect(printResultAsTable([], ['en' => ['lang/<info>.json' => ['<error>Hello</error>' => 'Hello']]]))
        ->toContain('| EN     | lang/<info>.json | <error>Hello</error> |');
});
