<?php

declare(strict_types=1);

use TranslationAudit\Enums\AuditType;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\IgnoredKeys;

it('ignores the listed keys in all locales and audits', function (AuditType $audit): void {
    $ignored_keys = IgnoredKeys::fromConfig(['Hello']);

    expect($ignored_keys->has('Hello', 'en', $audit))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'it', $audit))->toBeTrue()
        ->and($ignored_keys->has('Bye', 'en', $audit))->toBeFalse();
})->with(AuditType::cases());

it('ignores the keys mapped to locales only in those locales, in every audit', function (AuditType $audit): void {
    $ignored_keys = IgnoredKeys::fromConfig(['Hello' => ['it']]);

    expect($ignored_keys->has('Hello', 'it', $audit))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'en', $audit))->toBeFalse();
})->with(AuditType::cases());

it('ignores the keys mapped to an audit, as a case or its value, in all locales but only in that audit', function (mixed $audit): void {
    $ignored_keys = IgnoredKeys::fromConfig(['Hello' => $audit]);

    expect($ignored_keys->has('Hello', 'en', AuditType::Missing))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'it', AuditType::Missing))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'en', AuditType::Unused))->toBeFalse();
})->with([
    'case' => [AuditType::Missing],
    'value' => ['missing'],
]);

it('ignores the keys mapped to locales with an audit only in those locales and in that audit', function (): void {
    $ignored_keys = IgnoredKeys::fromConfig(['Hello' => ['it', AuditType::Unused, 'fr']]);

    expect($ignored_keys->has('Hello', 'it', AuditType::Unused))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'fr', AuditType::Unused))->toBeTrue()
        ->and($ignored_keys->has('Hello', 'en', AuditType::Unused))->toBeFalse()
        ->and($ignored_keys->has('Hello', 'it', AuditType::Missing))->toBeFalse();
});

it('ignores in all locales and audits a key both listed and mapped, in either order', function (array $values): void {
    $ignored_keys = IgnoredKeys::fromConfig($values);

    expect($ignored_keys->has('Hi', 'it', AuditType::Missing))->toBeTrue()
        ->and($ignored_keys->has('Hi', 'it', AuditType::Unused))->toBeTrue();
})->with([
    'listed after locales' => [['Hi' => ['en'], 'Hi']],
    'listed before locales' => [['Hi', 'Hi' => ['en']]],
    'listed after an audit' => [['Hi' => AuditType::Unused, 'Hi']],
    'listed before an audit' => [['Hi', 'Hi' => AuditType::Unused]],
]);

it('ignores no key when empty', function (): void {
    expect(IgnoredKeys::fromConfig([])->has('Hello', 'en', AuditType::Missing))->toBeFalse();
});

it('fails on an invalid entry', function (array $values): void {
    IgnoredKeys::fromConfig($values);
})->with([
    'empty key' => [['']],
    'non-string key' => [[1 => 2]],
    'key mapped to a string' => [['Hello' => 'en']],
    'key mapped to a non-list' => [['Hello' => ['locale' => 'en']]],
])->throws(InvalidConfigException::class, 'The "ignore_keys" config must contain only keys, or keys mapped to an AuditType case, or its value, or to a list of locales.');

it('fails on invalid locales', function (mixed $locale): void {
    IgnoredKeys::fromConfig(['Hi' => ['en', $locale]]);
})->with([
    'empty' => [''],
    'non-string' => [1],
])->throws(InvalidConfigException::class, 'The locales of the "Hi" key in the "ignore_keys" config must be non-empty strings, along with at most one AuditType case.');

it('fails on more than one audit among the locales', function (): void {
    IgnoredKeys::fromConfig(['Hi' => ['en', AuditType::Missing, AuditType::Unused]]);
})->throws(InvalidConfigException::class, 'The locales of the "Hi" key in the "ignore_keys" config must be non-empty strings, along with at most one AuditType case.');

it('fails on an audit without locales in the list', function (): void {
    IgnoredKeys::fromConfig(['Hi' => [AuditType::Missing]]);
})->throws(InvalidConfigException::class, 'The "Hi" key in the "ignore_keys" config must list its locales along with the AuditType case, or be mapped to the AuditType case alone.');
