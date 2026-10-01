<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use TranslationAudit\Actions\DetectUnusedTranslations;
use TranslationAudit\Data\Translation;
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
