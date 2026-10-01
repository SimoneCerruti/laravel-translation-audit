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

it('names the translation file that cannot be read', function (): void {
    putFile('lang/it.json', '{invalid');

    app(GetAppTranslationsForLocale::class)->handle('it');
})->throws(RuntimeException::class, 'Unable to read lang/it.json');
