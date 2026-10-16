<?php

declare(strict_types=1);

use TranslationAudit\Enums\AuditType;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\AuditScopedList;

it('applies the listed values to every audit', function (): void {
    $list = AuditScopedList::fromConfig('ignore_paths', ['app/Legacy/**', 'app/Old.php']);

    expect($list->forEveryAudit())->toBe(['app/Legacy/**', 'app/Old.php'])
        ->and($list->for(AuditType::Missing))->toBe(['app/Legacy/**', 'app/Old.php'])
        ->and($list->for(AuditType::Unused))->toBe(['app/Legacy/**', 'app/Old.php'])
        ->and($list->onlyFor(AuditType::Missing))->toBeEmpty();
});

it('limits the values mapped to an audit, as a case or its value, to that audit', function (): void {
    $list = AuditScopedList::fromConfig('ignore_paths', ['app/Legacy/**', 'database/**' => AuditType::Missing, 'app/Old.php' => 'unused']);

    expect($list->forEveryAudit())->toBe(['app/Legacy/**'])
        ->and($list->for(AuditType::Missing))->toBe(['app/Legacy/**', 'database/**'])
        ->and($list->for(AuditType::Unused))->toBe(['app/Legacy/**', 'app/Old.php'])
        ->and($list->onlyFor(AuditType::Missing))->toBe(['database/**'])
        ->and($list->onlyFor(AuditType::Unused))->toBe(['app/Old.php']);
});

it('returns a value listed more than once only once', function (): void {
    $list = AuditScopedList::fromConfig('ignore_locales', ['en', 'en']);

    expect($list->forEveryAudit())->toBe(['en']);
});

it('fails on an invalid entry, naming the config', function (array $config): void {
    AuditScopedList::fromConfig('ignore_locales', $config);
})->with([
    'empty value' => [['']],
    'non-string value' => [[1]],
    'nested list' => [[['en']]],
    'unknown audit' => [['en' => 'invalid']],
    'list of audits' => [['en' => [AuditType::Missing]]],
])->throws(InvalidConfigException::class, 'The "ignore_locales" config must contain only non-empty strings, or non-empty strings mapped to an AuditType case or its value.');
