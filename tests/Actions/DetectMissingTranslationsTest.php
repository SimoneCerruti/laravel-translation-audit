<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use TranslationAudit\Actions\DetectMissingTranslations;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\Translation;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\IgnoredKeys;

/**
 * Detect the missing translations of the keys used in each file.
 *
 * @param  array<string, list<DynamicTranslationKey|non-falsy-string>>  $keys
 * @param  list<string>  $locales
 * @param  array<array-key, mixed>  $ignore_keys  The ignore_keys config.
 * @return array<int, Translation>
 */
function detectMissingForKeys(array $keys, array $locales = ['en', 'it'], array $ignore_keys = []): array {
    $translation_keys = new Collection;

    foreach ($keys as $file => $values) {
        foreach ($values as $value) {
            $translation_keys->push(new UsedTranslationKey($file, $value));
        }
    }

    return app(DetectMissingTranslations::class)->handle($translation_keys, $locales, IgnoredKeys::fromConfig($ignore_keys))->all();
}

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

describe('dynamic keys', function (): void {
    beforeEach(function (): void {
        putFile('lang/en/payments.php', "<?php return ['card' => 'Card', 'paypal' => ['label' => 'PayPal']];");
        putFile('lang/it/payments.php', "<?php return ['card' => 'Carta'];");
        putJsonTranslations('it', ['Status active' => 'Stato attivo']);
    });

    it('returns a translation for each locale missing a key matching a dynamic key in another locale', function (): void {
        expect(detectMissingForKeys(['app/Payments.php' => [new DynamicTranslationKey(['payments.', ''])], 'app/Status.php' => [new DynamicTranslationKey(['Status ', ''])]]))->toEqual([
            new Translation('payments.paypal.label', 'it', 'app/Payments.php'),
            new Translation('Status active', 'en', 'app/Status.php'),
        ]);
    });

    it('returns a translation with the pattern of the dynamic key for each locale when no translation matches it', function (): void {
        expect(detectMissingForKeys(['app/Orders.php' => [new DynamicTranslationKey(['orders.', '.label'])]]))->toEqual([
            new Translation('orders.*.label', 'en', 'app/Orders.php'),
            new Translation('orders.*.label', 'it', 'app/Orders.php'),
        ]);
    });

    it('returns no translation when every locale defines the keys matching a dynamic key', function (): void {
        expect(detectMissingForKeys(['app/Payments.php' => [new DynamicTranslationKey(['payments.', '.label'])]], ['en']))->toBeEmpty();
    });

    it('matches the dynamic keys only against the audited locales', function (): void {
        putFile('lang/fr/payments.php', "<?php return ['card' => 'Carte', 'cash' => 'Espèces'];");

        expect(detectMissingForKeys(['app/Payments.php' => [new DynamicTranslationKey(['payments.', ''])]], ['en', 'it']))->toEqual([
            new Translation('payments.paypal.label', 'it', 'app/Payments.php'),
        ]);
    });

    it('skips the keys matching a dynamic key, and the dynamic keys by their pattern, ignored in a locale', function (): void {
        $keys = [
            'app/Payments.php' => [new DynamicTranslationKey(['payments.', ''])],
            'app/Orders.php' => [new DynamicTranslationKey(['orders.', '.label'])],
        ];

        expect(detectMissingForKeys($keys, ignore_keys: ['payments.paypal.label', 'orders.*.label' => ['it']]))->toEqual([
            new Translation('orders.*.label', 'en', 'app/Orders.php'),
        ]);
    });

    it('detects each missing translation once per file, whether the key is used statically or dynamically', function (): void {
        $keys = ['app/Payments.php' => [
            new DynamicTranslationKey(['payments.', '']),
            'payments.paypal.label',
            new DynamicTranslationKey(['payments.paypal.', '']),
            new DynamicTranslationKey(['payments.', '']),
        ]];

        expect(detectMissingForKeys($keys))->toEqual([new Translation('payments.paypal.label', 'it', 'app/Payments.php')]);
    });
});
