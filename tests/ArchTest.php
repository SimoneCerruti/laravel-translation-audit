<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Console\Commands\AuditCommand;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Console\Commands\PurgeUnusedTranslations;
use TranslationAudit\Contracts\TranslationKeyResolver;
use TranslationAudit\Data\Translation;
use TranslationAudit\Enums\AuditType;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Enums\SaveFormat;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Results\AuditTranslationsResult;
use TranslationAudit\Results\Contracts\Result;
use TranslationAudit\Results\PurgeUnusedTranslationsResult;
use TranslationAudit\TranslationAuditServiceProvider;

/** The classes of the public API, covered by semver: every other class of the package is internal. */
const PUBLIC_API = [
    TranslationAuditServiceProvider::class,
    AuditCommand::class,
    AuditTranslations::class,
    PurgeUnusedTranslations::class,
    TranslationKeyResolver::class,
    Translation::class,
    AuditType::class,
    DisplayFormat::class,
    SaveFormat::class,
    InvalidConfigException::class,
    Result::class,
    AuditTranslationsResult::class,
    PurgeUnusedTranslationsResult::class,
];

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('TranslationAudit')
    ->toUseStrictTypes();

arch('the results implement the result contract')
    ->expect('TranslationAudit\Results')
    ->classes()
    ->toImplement(Result::class)
    ->toHaveSuffix('Result')
    ->ignoring([Result::class, 'TranslationAudit\Results\Concerns']);

arch('the concrete classes are final')
    ->expect('TranslationAudit')
    ->classes()
    ->toBeFinal()
    ->ignoring(AuditCommand::class);

it('marks as internal every class outside the public API', function (string $class): void {
    $doc_comment = (string) new ReflectionClass($class)->getDocComment();

    expect(Str::contains($doc_comment, '@internal'))->toBe(! in_array($class, PUBLIC_API, true));
})->with(fn (): array => collect(Finder::create()->files()->in(__DIR__.'/../src'))
    ->values()
    ->map(fn (SplFileInfo $file): string => 'TranslationAudit\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
    ->all());
