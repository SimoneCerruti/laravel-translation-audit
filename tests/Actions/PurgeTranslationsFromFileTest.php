<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use TranslationAudit\Actions\PurgeTranslationsFromFile;

it('removes the keys from a json translation file, returning the removed translations', function (): void {
    putFile('lang/it.json', '{"Hello": "Ciao", "Goodbye.": "Arrivederci.", "list": ["a"], "Welcome": "Benvenuto"}');

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it.json', new Collection(['Hello', 'Goodbye.', 'list', 'Unknown']));

    expect($purged->purged->all())->toBe(['Hello' => 'Ciao', 'Goodbye.' => 'Arrivederci.'])
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

    expect(File::get(lang_path('it.json')))->toBe('{}');
});

it('keeps the indentation and the final newline of a json translation file', function (string $indentation, string $newline): void {
    putFile('lang/it.json', "{\n{$indentation}\"Hello\": \"Ciao\",\n{$indentation}\"list\": [\n{$indentation}{$indentation}\"a\"\n{$indentation}],\n{$indentation}\"Welcome\": \"Benvenuto\"\n}{$newline}");

    app(PurgeTranslationsFromFile::class)->handle('lang/it.json', new Collection(['Hello']));

    expect(File::get(lang_path('it.json')))->toBe("{\n{$indentation}\"list\": [\n{$indentation}{$indentation}\"a\"\n{$indentation}],\n{$indentation}\"Welcome\": \"Benvenuto\"\n}{$newline}");
})->with([
    'two spaces with a final newline' => ['  ', "\n"],
    'two spaces without a final newline' => ['  ', ''],
    'a tab with a final newline' => ["\t", "\n"],
    'four spaces without a final newline' => ['    ', ''],
]);

it('removes the keys of the group from a php translation file along with the parents left empty, returning the removed translations', function (): void {
    putFile('lang/it/admin/users.php', "<?php return ['title' => 'Utenti', 'quoted' => 'L\\'utente C:\\\\path', 'auth' => ['failed' => 'Accesso fallito', 'deep' => ['key' => 'Chiave']], 'list' => ['a', 'b'], 'empty' => []];");

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/admin/users.php', new Collection([
        'admin/users.auth.failed',
        'admin/users.auth.deep.key',
        'admin/users.list',
        'admin/users.missing',
        'messages.title',
    ]));

    expect($purged->purged->all())->toBe(['admin/users.auth.failed' => 'Accesso fallito', 'admin/users.auth.deep.key' => 'Chiave'])
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

    expect($purged->purged->all())->toBe(['flights.title' => 'Voli', 'flights.attributes.transactions.*.amount' => 'importo'])
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

it('does not purge a php translation file that does not return a literal array, returning why', function (): void {
    $contents = "<?php return array_merge(['welcome' => 'Benvenuto', 'list' => ['a']], ['title' => 'Titolo']);";
    putFile('lang/it/messages.php', $contents);

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/messages.php', new Collection(['messages.welcome', 'messages.list', 'messages.missing']));

    expect($purged->purged)->toBeEmpty()
        ->and($purged->not_purged->all())->toBe(['messages.welcome' => PurgeTranslationsFromFile::NOT_LITERAL_ARRAY])
        ->and(File::get(lang_path('it/messages.php')))->toBe($contents);
});

it('leaves the items whose key is known only by running the code in a php translation file, returning why', function (string $items, string $kept_key): void {
    putFile('lang/it/messages.php', "<?php return ['title' => 'Titolo', {$items}];");

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/messages.php', new Collection(['messages.title', $kept_key]));

    expect($purged->purged->all())->toBe(['messages.title' => 'Titolo'])
        ->and($purged->not_purged->all())->toBe([$kept_key => PurgeTranslationsFromFile::NOT_LITERAL_KEY])
        ->and(File::get(lang_path('it/messages.php')))->toBe("<?php return [{$items}];");
})->with([
    'a constant key' => ["PHP_INT_SIZE => 'Otto'", 'messages.'.PHP_INT_SIZE],
    'an item without a key after a constant key' => ["PHP_INT_SIZE => 'Otto', 'Nove'", 'messages.'.(PHP_INT_SIZE + 1)],
    'an item without a key after an unpacked array' => ["...['Foo'], 'Bar'", 'messages.1'],
    'a negative key' => ["-1 => 'Foo'", 'messages.-1'],
]);

it('does not rewrite a php translation file when its purged source would not return the other translations, returning why', function (): void {
    // The later 'title' key overrides the earlier one, so removing title.short removes its parent and brings back the overridden string.
    $contents = "<?php return ['title' => 'Titolo', 'title' => ['short' => 'Breve'], PHP_INT_SIZE => 'Otto'];";
    putFile('lang/it/messages.php', $contents);

    $purged = app(PurgeTranslationsFromFile::class)->handle('lang/it/messages.php', new Collection(['messages.title.short', 'messages.'.PHP_INT_SIZE]));

    expect($purged->purged)->toBeEmpty()
        ->and($purged->not_purged->all())->toBe(['messages.title.short' => PurgeTranslationsFromFile::NOT_SAME_TRANSLATIONS, 'messages.'.PHP_INT_SIZE => PurgeTranslationsFromFile::NOT_LITERAL_KEY])
        ->and(File::get(lang_path('it/messages.php')))->toBe($contents);
});

it('leaves the translation file untouched on a dry run, returning the translations that would be removed', function (string $file, string $contents, array $purged, array $not_purged): void {
    putFile($file, $contents);

    $result = app(PurgeTranslationsFromFile::class)->handle($file, new Collection(['messages.welcome', 'messages.title.short', 'Hello']), dry_run: true);

    expect($result->purged->all())->toBe($purged)
        ->and($result->not_purged->all())->toBe($not_purged)
        ->and(File::get(base_path($file)))->toBe($contents);
})->with([
    'json' => ['lang/it.json', '{"Hello": "Ciao"}', ['Hello' => 'Ciao'], []],
    'php' => ['lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto'];", ['messages.welcome' => 'Benvenuto'], []],
    'php that cannot be purged' => [
        'lang/it/messages.php',
        "<?php return ['welcome' => 'Benvenuto', 'title' => 'Titolo', 'title' => ['short' => 'Breve']];",
        [],
        ['messages.welcome' => PurgeTranslationsFromFile::NOT_SAME_TRANSLATIONS, 'messages.title.short' => PurgeTranslationsFromFile::NOT_SAME_TRANSLATIONS],
    ],
]);

it('does not rewrite the translation file when no key is removed', function (string $file, string $contents): void {
    putFile($file, $contents);

    $purged = app(PurgeTranslationsFromFile::class)->handle($file, new Collection(['messages.missing', 'Missing']));

    expect($purged->purged)->toBeEmpty()
        ->and($purged->not_purged)->toBeEmpty()
        ->and(File::get(base_path($file)))->toBe($contents);
})->with([
    'json' => ['lang/it.json', '{"Hello":"Ciao"}'],
    'php' => ['lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto'];"],
]);

it('refuses the files that are neither json nor php translation files', function (): void {
    putFile('lang/it.yaml', 'Hello: Ciao');

    app(PurgeTranslationsFromFile::class)->handle('lang/it.yaml', new Collection(['Hello']));
})->throws(InvalidArgumentException::class, 'Unable to purge lang/it.yaml: only the JSON and PHP translation files are supported.');
