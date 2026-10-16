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

        // the lines added before each test mark the json translations as loaded: a fresh translator reads the files, as in an app.
        app()->forgetInstance('translator');
        Lang::clearResolvedInstance('translator');
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

    it('detects a key matching a dynamic key as missing only when the translator lacks it, like a static key', function (): void {
        putFile('lang/en/plans.php', "<?php return ['basic' => 'Basic', 'pro' => ['label' => 'Pro']];");
        putFile('lang/it/plans.php', "<?php return ['basic' => ['label' => 'Base'], 'pro' => 'Pro'];");

        expect(detectMissingForKeys(['app/Plans.php' => [new DynamicTranslationKey(['plans.', ''])]]))->toEqual([
            new Translation('plans.basic.label', 'en', 'app/Plans.php'),
            new Translation('plans.pro.label', 'it', 'app/Plans.php'),
        ]);
    });

    it('detects a key matching a dynamic key as present in the locale of the translations of the framework', function (): void {
        putFile('lang/en/validation.php', "<?php return ['custom' => ['email' => ['required' => 'Email!']]];");
        putFile('lang/it/validation.php', "<?php return ['ends_with' => 'Deve finire con :values.'];");

        expect(detectMissingForKeys(['app/Rules.php' => [new DynamicTranslationKey(['validation.', ''])]]))->toEqual([
            new Translation('validation.custom.email.required', 'it', 'app/Rules.php'),
        ]);
    });

    it('detects a key matching a dynamic key as missing in every locale when the translator cannot resolve it, like a key with dots in an array key', function (): void {
        putFile('lang/en/settings.php', "<?php return ['ipmi.type' => ['help' => 'The IPMI type']];");
        putFile('lang/it/settings.php', "<?php return ['ipmi.type' => ['help' => 'Il tipo IPMI']];");

        expect(detectMissingForKeys(['app/Settings.php' => [new DynamicTranslationKey(['settings.', '.help'])]]))->toEqual([
            new Translation('settings.ipmi.type.help', 'en', 'app/Settings.php'),
            new Translation('settings.ipmi.type.help', 'it', 'app/Settings.php'),
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
