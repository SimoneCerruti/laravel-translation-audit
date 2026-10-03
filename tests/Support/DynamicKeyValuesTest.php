<?php

declare(strict_types=1);

use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\DynamicKeyValues;

enum DynamicKeyValuesTestStatus: string {
    case Active = 'active';
    case Suspended = 'suspended';
}

enum DynamicKeyValuesTestLevel: int {
    case Low = 1;
    case High = 2;
}

enum DynamicKeyValuesTestUnitEnum {
    case Card;
}

/**
 * Expand the dynamic keys used in a file with the dynamic_keys config, returning the resulting keys.
 *
 * @param  array<array-key, mixed>  $config
 * @param  list<non-falsy-string|DynamicTranslationKey>  $keys
 * @return list<non-falsy-string|DynamicTranslationKey>
 */
function expandDynamicKeys(array $config, array $keys): array {
    $translation_keys = collect($keys)->map(fn (string|DynamicTranslationKey $key): UsedTranslationKey => new UsedTranslationKey('app/Example.php', $key));

    return DynamicKeyValues::fromConfig($config)
        ->expand($translation_keys)
        ->map(fn (UsedTranslationKey $key): string|DynamicTranslationKey => $key->value)
        ->values()
        ->all();
}

it('expands a dynamic key into a key for each value of its pattern', function (array|string $values, array $expected): void {
    expect(expandDynamicKeys(['status.*' => $values], [new DynamicTranslationKey(['status.', ''])]))->toBe($expected);
})->with([
    'list of strings' => [['active', 'suspended'], ['status.active', 'status.suspended']],
    'list of integers' => [[1, 2], ['status.1', 'status.2']],
    'string backed enum' => [DynamicKeyValuesTestStatus::class, ['status.active', 'status.suspended']],
    'int backed enum' => [DynamicKeyValuesTestLevel::class, ['status.1', 'status.2']],
]);

it('keeps the file of the expanded dynamic key', function (): void {
    $translation_keys = collect([
        new UsedTranslationKey('app/First.php', new DynamicTranslationKey(['status.', ''])),
        new UsedTranslationKey('app/Second.php', 'Hello'),
    ]);

    expect(DynamicKeyValues::fromConfig(['status.*' => ['active', 'suspended']])->expand($translation_keys)->all())->toEqual([
        new UsedTranslationKey('app/First.php', 'status.active'),
        new UsedTranslationKey('app/First.php', 'status.suspended'),
        new UsedTranslationKey('app/Second.php', 'Hello'),
    ]);
});

it('expands a dynamic key with static texts on both sides of its dynamic part', function (): void {
    expect(expandDynamicKeys(['status.*.label' => ['active']], [new DynamicTranslationKey(['status.', '.label'])]))->toBe(['status.active.label']);
});

it('keeps the static keys and the dynamic keys without values in the config', function (): void {
    $keys = ['status.active', new DynamicTranslationKey(['orders.', '']), new DynamicTranslationKey(['status.', '.label'])];

    expect(expandDynamicKeys(['status.*' => ['active']], $keys))->toEqual($keys);
});

it('keeps the dynamic keys with more dynamic parts than the pattern', function (): void {
    $keys = [new DynamicTranslationKey(['status.', '.', ''])];

    expect(expandDynamicKeys(['status.*' => ['active']], $keys))->toEqual($keys);
});

it('fails on an invalid pattern', function (array $config): void {
    DynamicKeyValues::fromConfig($config);
})->with([
    'without asterisk' => [['status' => ['active']]],
    'with more asterisks' => [['status.*.*' => ['active']]],
    'only an asterisk' => [['*' => ['active']]],
    'non-string pattern' => [[['active']]],
])->throws(InvalidConfigException::class, 'The "dynamic_keys" config must map each pattern, with a single asterisk in place of the dynamic part and some static text, to its values.');

it('fails on invalid values', function (mixed $values): void {
    DynamicKeyValues::fromConfig(['status.*' => $values]);
})->with([
    'empty list' => [[]],
    'non-list' => [['a' => 'active']],
    'empty string' => [['active', '']],
    'non-scalar value' => [[['active']]],
    'float value' => [[1.5]],
    'unknown class' => ['App\\Enums\\Missing'],
    'non-enum class' => [stdClass::class],
    'unit enum' => [DynamicKeyValuesTestUnitEnum::class],
])->throws(InvalidConfigException::class, 'The values of the "status.*" pattern in the "dynamic_keys" config must be a backed enum class, or a non-empty list of non-empty strings or integers.');
