<?php

declare(strict_types=1);

use TranslationAudit\Actions\GetAppTranslationsForLocale;
use TranslationAudit\Data\Translation;

it('returns the translations of the json and php translation files of the locale', function (): void {
    putJsonTranslations('it', ['Hello' => 'Ciao']);
    putFile('lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto', 'auth' => ['failed' => 'Accesso fallito']];");
    putFile('lang/it/admin/users.php', "<?php return ['title' => 'Utenti'];");
    putJsonTranslations('en', ['Hello' => 'Hello']);

    $translations = app(GetAppTranslationsForLocale::class)->handle('it');

    expect($translations->all())->toEqual([
        new Translation('Hello', 'it', 'lang/it.json', 'Ciao'),
        new Translation('admin/users.title', 'it', 'lang/it/admin/users.php', 'Utenti'),
        new Translation('messages.welcome', 'it', 'lang/it/messages.php', 'Benvenuto'),
        new Translation('messages.auth.failed', 'it', 'lang/it/messages.php', 'Accesso fallito'),
    ]);
});

it('skips the falsy keys and the values that are not strings', function (): void {
    putFile('lang/it.json', '{"": "Vuoto", "0": "Zero", "list": ["a"], "count": 1, "Hello": "Ciao"}');

    $translations = app(GetAppTranslationsForLocale::class)->handle('it');

    expect($translations->all())->toEqual([new Translation('Hello', 'it', 'lang/it.json', 'Ciao')]);
});

it('returns no translation when the locale has no translation files', function (): void {
    expect(app(GetAppTranslationsForLocale::class)->handle('it'))->toBeEmpty();
});

it('names the translation file that cannot be read', function (string $path, string $contents, string $error): void {
    putFile($path, $contents);

    expect(fn () => app(GetAppTranslationsForLocale::class)->handle('it'))->toThrow(RuntimeException::class, "Unable to read {$path}: {$error}");
})->with([
    'invalid json' => ['lang/it.json', '{invalid', 'Syntax error'],
    'json without an array' => ['lang/it.json', '"Ciao"', 'The file does not hold an array of translations, string given.'],
    'empty php' => ['lang/it/messages.php', '<?php', 'The file does not hold an array of translations, int given.'],
    'invalid php' => ['lang/it/messages.php', '<?php return [', "Unclosed '['"],
    'php error' => ['lang/it/messages.php', '<?php return [Missing::A];', 'Class "Missing" not found'],
]);

it('skips the translation files that cannot be read with the skipped files callback, calling it with the error naming the file', function (): void {
    putFile('lang/it.json', '{invalid');
    putFile('lang/it/broken.php', '<?php');
    putFile('lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto'];");
    $skipped = [];

    $translations = app(GetAppTranslationsForLocale::class)->handle('it', function (string $path, RuntimeException $error) use (&$skipped): void {
        $skipped[$path] = $error->getMessage();
    });

    expect($translations->all())->toEqual([new Translation('messages.welcome', 'it', 'lang/it/messages.php', 'Benvenuto')])
        ->and(array_keys($skipped))->toBe(['lang/it.json', 'lang/it/broken.php'])
        ->and($skipped['lang/it.json'])->toStartWith('Unable to read lang/it.json: Syntax error')
        ->and($skipped['lang/it/broken.php'])->toBe('Unable to read lang/it/broken.php: The file does not hold an array of translations, int given.');
});
