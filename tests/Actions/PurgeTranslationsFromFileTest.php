<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use TranslationAudit\Actions\PurgeTranslationsFromFile;

it('removes the keys from a json translation file, returning the removed translations', function (): void {
    putFile('lang/it.json', '{"Hello": "Ciao", "Goodbye.": "Arrivederci.", "list": ["a"], "Welcome": "Benvenuto"}');

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it.json', new Collection(['Hello', 'Goodbye.', 'list', 'Unknown']));

    expect($purged->all())->toBe(['Hello' => 'Ciao', 'Goodbye.' => 'Arrivederci.'])
        ->and(File::get(lang_path('it.json')))->toBe(<<<'JSON'
            {
                "list": [
                    "a"
                ],
                "Welcome": "Benvenuto"
            }

            JSON);
});

it('leaves an empty object when every key is removed from a json translation file', function (): void {
    putJsonTranslations('it', ['Hello' => 'Ciao']);

    app(PurgeTranslationsFromFile::class)->handle('lang/it.json', new Collection(['Hello']));

    expect(File::get(lang_path('it.json')))->toBe("{}\n");
});

it('removes the keys of the group from a php translation file along with the parents left empty, returning the removed translations', function (): void {
    putFile('lang/it/admin/users.php', "<?php return ['title' => 'Utenti', 'quoted' => 'L\\'utente C:\\\\path', 'auth' => ['failed' => 'Accesso fallito', 'deep' => ['key' => 'Chiave']], 'list' => ['a', 'b'], 'empty' => []];");

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/admin/users.php', new Collection([
        'admin/users.auth.failed',
        'admin/users.auth.deep.key',
        'admin/users.list',
        'admin/users.missing',
        'messages.title',
    ]));

    expect($purged->all())->toBe(['admin/users.auth.failed' => 'Accesso fallito', 'admin/users.auth.deep.key' => 'Chiave'])
        ->and(File::get(lang_path('it/admin/users.php')))->toBe(<<<'PHP'
            <?php return ['title' => 'Utenti', 'quoted' => 'L\'utente C:\\path', 'list' => ['a', 'b'], 'empty' => []];
            PHP)
        ->and(File::getRequire(lang_path('it/admin/users.php')))->toBe(['title' => 'Utenti', 'quoted' => "L'utente C:\\path", 'list' => ['a', 'b'], 'empty' => []]);
});

it('removes the items from the source of a php translation file, leaving the rest of the source untouched', function (): void {
    config(['app.speed' => 900]);
    putFile('lang/it/flights.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Support\Str;

        // The flights page.
        return [
            'title' => 'Voli', // the page title
            'speed' => 'Predefinita ('.config('app.speed', 450).' kt)',
            'route' => Str::upper('rotta'),
            'attributes' => [
                'transactions.*.amount' => 'importo',
            ],
        ];

        PHP);

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/flights.php', new Collection(['flights.title', 'flights.attributes.transactions.*.amount']));

    expect($purged->all())->toBe(['flights.title' => 'Voli', 'flights.attributes.transactions.*.amount' => 'importo'])
        ->and(File::get(lang_path('it/flights.php')))->toBe(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Support\Str;

            // The flights page.
            return [
                'speed' => 'Predefinita ('.config('app.speed', 450).' kt)',
                'route' => Str::upper('rotta'),
            ];

            PHP);
});

it('does not purge a php translation file that does not return a literal array', function (): void {
    $contents = "<?php return array_merge(['welcome' => 'Benvenuto'], ['title' => 'Titolo']);";
    putFile('lang/it/messages.php', $contents);

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/messages.php', new Collection(['messages.welcome']));

    expect($purged)->toBeEmpty()
        ->and(File::get(lang_path('it/messages.php')))->toBe($contents);
});

it('does not rewrite a php translation file when its purged source would not return the other translations', function (): void {
    // The later 'title' key overrides the earlier one, so removing title.short removes its parent and brings back the overridden string.
    $contents = "<?php return ['title' => 'Titolo', 'title' => ['short' => 'Breve']];";
    putFile('lang/it/messages.php', $contents);

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/messages.php', new Collection(['messages.title.short']));

    expect($purged)->toBeEmpty()
        ->and(File::get(lang_path('it/messages.php')))->toBe($contents);
});

it('does not rewrite the translation file when no key is removed', function (string $file, string $contents): void {
    putFile($file, $contents);

    $purged = app(PurgeTranslationsFromFile::class)->handle($file, new Collection(['messages.missing', 'Missing']));

    expect($purged)->toBeEmpty()
        ->and(File::get(base_path($file)))->toBe($contents);
})->with([
    'json' => ['lang/it.json', '{"Hello":"Ciao"}'],
    'php' => ['lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto'];"],
]);

it('refuses the files that are neither json nor php translation files', function (): void {
    putFile('lang/it.yaml', 'Hello: Ciao');

    app(PurgeTranslationsFromFile::class)->handle('lang/it.yaml', new Collection(['Hello']));
})->throws(InvalidArgumentException::class, 'Unable to purge lang/it.yaml: only the JSON and PHP translation files are supported.');
