<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Actions\PrintResultAsList;

function printResultAsList(array $missing, int $columns = 80, ?array $unused = null): string {
    $output = new BufferedOutput;

    putenv("COLUMNS={$columns}");

    try {
        app(PrintResultAsList::class)->handle(['missing' => $missing, ...($unused === null ? [] : ['unused' => $unused])], $output);
    } finally {
        putenv('COLUMNS');
    }

    return str_replace(PHP_EOL, "\n", $output->fetch());
}

it('prints the keys grouped by file with the missing locales aligned before each key', function (): void {
    expect(printResultAsList([
        'app/First.php' => ['Hello' => ['it'], 'messages.goodbye' => ['en', 'it']],
        'app/Second.php' => ['Hello' => ['en']],
    ]))->toBe(<<<'TXT'
          app/First.php
            IT      Hello
            EN, IT  messages.goodbye

          app/Second.php
            EN      Hello

        TXT);
});

it('wraps the long keys aligned under the key column', function (): void {
    expect(printResultAsList(['app/Example.php' => ['Welcome back! Please sign in to continue with your account.' => ['en', 'it']]], columns: 50))
        ->toBe(<<<'TXT'
              app/Example.php
                EN, IT  Welcome back! Please sign in to
                        continue with your account.

            TXT);
});

it('does not wrap the keys when the terminal is too narrow', function (): void {
    expect(printResultAsList(['app/Example.php' => ['Welcome back! Please sign in to continue with your account.' => ['en', 'it']]], columns: 30))
        ->toContain('    EN, IT  Welcome back! Please sign in to continue with your account.');
});

it('prints the keys and files containing console tags verbatim', function (): void {
    expect(printResultAsList(['app/<info>.php' => ['<error>Hello</error>' => ['en']]]))->toBe(<<<'TXT'
          app/<info>.php
            EN  <error>Hello</error>

        TXT);
});

it('prints numeric keys', function (): void {
    expect(printResultAsList(['app/Example.php' => [404 => ['en']]]))->toContain('    EN  404');
});

it('prints the missing and the unused translations under their headings', function (): void {
    expect(printResultAsList(
        ['app/Example.php' => ['Hello' => ['it']]],
        unused: [
            'en' => ['lang/en/messages.php' => ['messages.old' => 'Old']],
            'it' => ['lang/it.json' => ['Bye' => 'Ciao'], 'lang/it/messages.php' => ['messages.old' => 'Vecchio', 'messages.older' => 'Più vecchio']],
        ],
    ))->toBe(<<<'TXT'
        Missing translations

          app/Example.php
            IT  Hello

        Unused translations

          EN
            lang/en/messages.php
              messages.old
                Old

          IT
            lang/it.json
              Bye
                Ciao
            lang/it/messages.php
              messages.old
                Vecchio
              messages.older
                Più vecchio

        TXT);
});

it('prints only the heading of the non-empty section', function (array $missing, array $unused, string $heading, string $other_heading): void {
    expect(printResultAsList($missing, unused: $unused))->toStartWith($heading)
        ->not->toContain($other_heading);
})->with([
    'only missing' => [['app/Example.php' => ['Hello' => ['it']]], [], 'Missing translations', 'Unused translations'],
    'only unused' => [[], ['it' => ['lang/it.json' => ['Bye' => 'Ciao']]], 'Unused translations', 'Missing translations'],
]);

it('wraps the long unused keys and translations aligned under their column', function (): void {
    expect(printResultAsList([], columns: 40, unused: ['en' => ['lang/en.json' => ['Welcome back! Please sign in to continue.' => 'Welcome back! Please sign in to continue.']]]))
        ->toContain(<<<'TXT'
                  Welcome back! Please sign in to
                  continue.
                    Welcome back! Please sign in to
                    continue.
            TXT);
});

it('indents every line of the multiline translations', function (): void {
    expect(printResultAsList([], unused: ['en' => ['lang/en.json' => ['Address' => "Street\nCity"]]]))
        ->toContain("      Address\n        Street\n        City\n");
});

it('prints the unused keys and files containing console tags verbatim', function (): void {
    expect(printResultAsList([], unused: ['en' => ['lang/<info>.json' => ['<error>Hello</error>' => '<comment>Ciao</comment>']]]))
        ->toContain("    lang/<info>.json\n      <error>Hello</error>\n        <comment>Ciao</comment>\n");
});
