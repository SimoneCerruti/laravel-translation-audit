<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;
use TranslationAudit\Actions\DetectMissingTranslations;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

beforeEach(function (): void {
    Lang::addLines(['*.Hello' => 'Ciao'], 'it');
    Lang::addLines(['*.Hello' => 'Hello', '*.Bye' => 'Bye'], 'en');
});

it('returns a translation for each locale missing a key used in a file', function (): void {
    $missing = app(DetectMissingTranslations::class)->handle(
        usedTranslationKeys(['app/First.php' => ['Hello', 'Bye'], 'app/Second.php' => ['Bye', 'Unknown']]),
        ['en', 'it'],
        IgnoredKeys::fromConfig([]),
    );

    expect($missing->all())->toEqual([
        new Translation('Bye', 'it', 'app/First.php'),
        new Translation('Bye', 'it', 'app/Second.php'),
        new Translation('Unknown', 'en', 'app/Second.php'),
        new Translation('Unknown', 'it', 'app/Second.php'),
    ]);
});

it('detects each key once per file', function (): void {
    $missing = app(DetectMissingTranslations::class)->handle(usedTranslationKeys(['app/Example.php' => ['Bye', 'Bye']]), ['it'], IgnoredKeys::fromConfig([]));

    expect($missing->all())->toEqual([new Translation('Bye', 'it', 'app/Example.php')]);
});

it('skips the keys ignored in a locale', function (): void {
    $missing = app(DetectMissingTranslations::class)->handle(
        usedTranslationKeys(['app/Example.php' => ['Unknown']]),
        ['en', 'it'],
        IgnoredKeys::fromConfig(['Unknown' => ['it']]),
    );

    expect($missing->all())->toEqual([new Translation('Unknown', 'en', 'app/Example.php')]);
});

it('leaves the value of the missing translations empty', function (): void {
    $missing = app(DetectMissingTranslations::class)->handle(usedTranslationKeys(['app/Example.php' => ['Unknown']]), ['it'], IgnoredKeys::fromConfig([]));

    expect($missing->first()?->value)->toBeNull();
});

it('skips the dynamic keys, whose values are unknown', function (): void {
    $translation_keys = usedTranslationKeys(['app/Example.php' => ['Bye']])
        ->push(new UsedTranslationKey('app/Example.php', new DynamicTranslationKey(['payments.', ''])));

    $missing = app(DetectMissingTranslations::class)->handle($translation_keys, ['it'], IgnoredKeys::fromConfig([]));

    expect($missing->all())->toEqual([new Translation('Bye', 'it', 'app/Example.php')]);
});
