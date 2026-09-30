<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Actions\PrintResultAsList;

function printResultAsList(array $missing, int $columns = 80): string {
    $output = new BufferedOutput;

    putenv("COLUMNS={$columns}");

    try {
        app(PrintResultAsList::class)->handle($missing, $output);
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
