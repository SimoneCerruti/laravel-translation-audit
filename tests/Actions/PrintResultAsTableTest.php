<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Actions\PrintResultAsTable;

function printResultAsTable(array $missing): string {
    $output = new BufferedOutput;

    app(PrintResultAsTable::class)->handle($missing, $output);

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
