<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\AdditionalKeys;

it('adds the keys as used in the config file', function (): void {
    $translation_keys = AdditionalKeys::fromConfig(['Hello', 'messages.welcome'])->apply(usedTranslationKeys(['app/Example.php' => ['Bye']]));

    expect($translation_keys)->toEqual(usedTranslationKeys([
        'app/Example.php' => ['Bye'],
        'config/translation-audit.php' => ['Hello', 'messages.welcome'],
    ]));
});

it('adds a pattern as a dynamic key', function (string $pattern, array $segments): void {
    $translation_keys = AdditionalKeys::fromConfig([$pattern])->apply(new Collection);

    expect($translation_keys)->toEqual(new Collection([new UsedTranslationKey('config/translation-audit.php', new DynamicTranslationKey($segments))]));
})->with([
    'trailing asterisk' => ['payments.*', ['payments.', '']],
    'asterisks in the middle' => ['status.*.label.*', ['status.', '.label.', '']],
]);

it('adds no key when empty', function (): void {
    expect(AdditionalKeys::fromConfig([])->apply(usedTranslationKeys(['app/Example.php' => ['Bye']])))
        ->toEqual(usedTranslationKeys(['app/Example.php' => ['Bye']]));
});

it('fails on an entry that is neither a key nor a pattern with some static text', function (mixed $value): void {
    expect(fn (): AdditionalKeys => AdditionalKeys::fromConfig(['Hello', $value]))
        ->toThrow(InvalidConfigException::class, 'The "additional_keys" config must contain only keys, or patterns of dynamic keys with an asterisk in place of each dynamic part and some static text.');
})->with([
    'empty string' => '',
    'falsy string' => '0',
    'only asterisks' => '**',
    'integer' => 1,
    'null' => null,
    'nested array' => [['Hello']],
]);
