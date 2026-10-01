<?php

declare(strict_types=1);

use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\IgnoredKeys;

it('ignores the listed keys in all locales', function (): void {
    $ignored_keys = IgnoredKeys::fromConfig(['Hello']);

    expect($ignored_keys->has('Hello', 'en'))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'it'))->toBeTrue()
        ->and($ignored_keys->has('Bye', 'en'))->toBeFalse();
});

it('ignores the keys mapped to locales only in those locales', function (): void {
    $ignored_keys = IgnoredKeys::fromConfig(['Hello' => ['it']]);

    expect($ignored_keys->has('Hello', 'it'))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'en'))->toBeFalse();
});

it('ignores in all locales a key both listed and mapped to locales, in either order', function (array $values): void {
    expect(IgnoredKeys::fromConfig($values)->has('Hi', 'it'))->toBeTrue();
})->with([
    'listed after' => [['Hi' => ['en'], 'Hi']],
    'listed before' => [['Hi', 'Hi' => ['en']]],
]);

it('ignores no key when empty', function (): void {
    expect(IgnoredKeys::fromConfig([])->has('Hello', 'en'))->toBeFalse();
});

it('fails on an invalid entry', function (array $values): void {
    IgnoredKeys::fromConfig($values);
})->with([
    'empty key' => [['']],
    'non-string key' => [[1 => 2]],
    'key mapped to a string' => [['Hello' => 'en']],
    'key mapped to a non-list' => [['Hello' => ['locale' => 'en']]],
])->throws(InvalidConfigException::class, 'The "ignore_keys" config must contain only keys, or keys mapped to a list of locales.');

it('fails on invalid locales', function (mixed $locale): void {
    IgnoredKeys::fromConfig(['Hi' => ['en', $locale]]);
})->with([
    'empty' => [''],
    'non-string' => [1],
])->throws(InvalidConfigException::class, 'The locales of the "Hi" key in the "ignore_keys" config must be non-empty strings.');
