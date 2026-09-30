<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use TranslationAudit\Actions\PrintResultAsJson;

function printResultAsJson(array $missing): string {
    $output = new BufferedOutput;

    app(PrintResultAsJson::class)->handle($missing, $output);

    return $output->fetch();
}

it('prints the missing locales of each key grouped by file as json on a single line', function (): void {
    expect(printResultAsJson([
        'app/First.php' => ['Hello' => ['it'], 'messages.goodbye' => ['en', 'it']],
        'app/Second.php' => ['Città' => ['en']],
    ]))->toBe('{"app/First.php":{"Hello":["it"],"messages.goodbye":["en","it"]},"app/Second.php":{"Città":["en"]}}'.PHP_EOL);
});

it('prints the keys and files containing console tags verbatim', function (): void {
    $missing = ['app/<info>.php' => ['<error>Hello</error>' => ['en']]];

    expect(json_decode(printResultAsJson($missing), true, flags: JSON_THROW_ON_ERROR))->toBe($missing);
});
