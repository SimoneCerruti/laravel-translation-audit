<?php

declare(strict_types=1);

use TranslationAudit\Actions\BuildAuditResult;
use TranslationAudit\Data\Translation;
use TranslationAudit\Support\IgnoredKeys;

it('returns the missing translations without auditing the unused ones', function (): void {
    putJsonTranslations('it', ['Hello' => 'Ciao', 'Bye' => 'Arrivederci']);

    $result = app(BuildAuditResult::class)->handle(usedTranslationKeys(['app/Example.php' => ['Hello', 'Welcome']]), ['it'], IgnoredKeys::fromConfig([]), false, []);

    expect($result->missing->all())->toEqual([new Translation('Welcome', 'it', 'app/Example.php')])
        ->and($result->unused)->toBeNull();
});

it('returns the missing and the unused translations when auditing the unused ones', function (): void {
    putJsonTranslations('it', ['Hello' => 'Ciao', 'Bye' => 'Arrivederci']);

    $result = app(BuildAuditResult::class)->handle(usedTranslationKeys(['app/Example.php' => ['Hello', 'Welcome']]), ['it'], IgnoredKeys::fromConfig([]), true, []);

    expect($result->missing->all())->toEqual([new Translation('Welcome', 'it', 'app/Example.php')])
        ->and($result->unused?->all())->toEqual([new Translation('Bye', 'it', 'lang/it.json', 'Arrivederci')]);
});

it('skips the ignored keys and the ignored translation files', function (): void {
    putJsonTranslations('it', ['Bye' => 'Arrivederci']);
    putFile('lang/it/messages.php', "<?php return ['old' => 'Vecchio'];");

    $result = app(BuildAuditResult::class)->handle(usedTranslationKeys(['app/Example.php' => ['Welcome']]), ['it'], IgnoredKeys::fromConfig(['Welcome', 'Bye']), true, ['lang/it/*']);

    expect($result->missing)->toBeEmpty()
        ->and($result->unused)->toBeEmpty();
});
