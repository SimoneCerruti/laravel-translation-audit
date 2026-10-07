<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use TranslationAudit\Actions\DetectUnusedTranslations;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

it('returns a translation with its value for each key defined in a translation file but used in no file', function (): void {
    putJsonTranslations('it', ['Hello' => 'Ciao', 'Bye' => 'Arrivederci']);
    putFile('lang/it/messages.php', "<?php return ['old' => 'Vecchio', 'new' => 'Nuovo'];");

    $unused = app(DetectUnusedTranslations::class)->handle(usedTranslationKeys(['app/Example.php' => ['Hello', 'messages.new']]), ['it'], [], IgnoredKeys::fromConfig([]));

    expect($unused->all())->toEqual([
        new Translation('Bye', 'it', 'lang/it.json', 'Arrivederci'),
        new Translation('messages.old', 'it', 'lang/it/messages.php', 'Vecchio'),
    ]);
});

it('skips the translation files matching the ignored paths', function (): void {
    putJsonTranslations('it', ['Bye' => 'Arrivederci']);
    putFile('lang/it/messages.php', "<?php return ['old' => 'Vecchio'];");

    $unused = app(DetectUnusedTranslations::class)->handle(new Collection, ['it'], ['lang/it/*'], IgnoredKeys::fromConfig([]));

    expect($unused->all())->toEqual([new Translation('Bye', 'it', 'lang/it.json', 'Arrivederci')]);
});

it('skips the keys ignored in a locale', function (): void {
    putJsonTranslations('en', ['Bye' => 'Bye']);
    putJsonTranslations('it', ['Bye' => 'Arrivederci']);

    $unused = app(DetectUnusedTranslations::class)->handle(new Collection, ['en', 'it'], [], IgnoredKeys::fromConfig(['Bye' => ['it']]));

    expect($unused->all())->toEqual([new Translation('Bye', 'en', 'lang/en.json', 'Bye')]);
});

it('considers used the keys matching a dynamic key', function (): void {
    putJsonTranslations('it', ['Status active' => 'Stato attivo', 'Bye' => 'Arrivederci']);
    putFile('lang/it/payments.php', "<?php return ['card' => 'Carta', 'paypal' => ['label' => 'PayPal'], 'title' => 'Pagamenti'];");
    putFile('lang/it/orders.php', "<?php return ['title' => 'Ordini', 'total' => 'Totale'];");

    $unused = app(DetectUnusedTranslations::class)->handle(
        new Collection([
            new UsedTranslationKey('app/Payments.php', new DynamicTranslationKey(['payments.', ''])),
            new UsedTranslationKey('app/Orders.php', new DynamicTranslationKey(['', '.title'])),
            new UsedTranslationKey('app/Status.php', new DynamicTranslationKey(['Status ', ''])),
        ]),
        ['it'],
        ['lang/it/payments.php'],
        IgnoredKeys::fromConfig([]),
    );

    expect($unused->all())->toEqual([
        new Translation('Bye', 'it', 'lang/it.json', 'Arrivederci'),
        new Translation('orders.total', 'it', 'lang/it/orders.php', 'Totale'),
    ]);
});

it('matches the dynamic keys literally, besides their dynamic parts', function (): void {
    putJsonTranslations('it', ['a+b' => 'A più B', 'aab' => 'AAB', 'a+bc' => 'A più BC']);

    $unused = app(DetectUnusedTranslations::class)->handle(
        new Collection([new UsedTranslationKey('app/Example.php', new DynamicTranslationKey(['a+', 'c']))]),
        ['it'],
        [],
        IgnoredKeys::fromConfig([]),
    );

    expect($unused->all())->toEqual([
        new Translation('a+b', 'it', 'lang/it.json', 'A più B'),
        new Translation('aab', 'it', 'lang/it.json', 'AAB'),
    ]);
});

it('considers used the children of a key returning an array', function (): void {
    putFile('lang/it/settings.php', "<?php return ['languages' => ['en' => 'Inglese', 'it' => ['short' => 'IT']], 'title' => 'Impostazioni'];");
    putFile('lang/it/editor.php', "<?php return ['save' => 'Salva', 'cancel' => 'Annulla'];");
    putFile('lang/it/orders.php', "<?php return ['status' => ['open' => 'Aperto'], 'total' => 'Totale'];");

    $unused = app(DetectUnusedTranslations::class)->handle(
        usedTranslationKeys(['app/Example.php' => ['settings.languages', 'editor']])
            ->push(new UsedTranslationKey('app/Orders.php', new DynamicTranslationKey(['', '.status']))),
        ['it'],
        [],
        IgnoredKeys::fromConfig([]),
    );

    expect($unused->all())->toEqual([
        new Translation('orders.total', 'it', 'lang/it/orders.php', 'Totale'),
        new Translation('settings.title', 'it', 'lang/it/settings.php', 'Impostazioni'),
    ]);
});

it('does not consider used the JSON translations sharing the prefix of a used key', function (): void {
    putJsonTranslations('it', ['Hello.' => 'Ciao.', 'Hello. World' => 'Ciao. Mondo']);

    $unused = app(DetectUnusedTranslations::class)->handle(usedTranslationKeys(['app/Example.php' => ['Hello']]), ['it'], [], IgnoredKeys::fromConfig([]));

    expect($unused->all())->toEqual([
        new Translation('Hello.', 'it', 'lang/it.json', 'Ciao.'),
        new Translation('Hello. World', 'it', 'lang/it.json', 'Ciao. Mondo'),
    ]);
});
