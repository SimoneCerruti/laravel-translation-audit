<?php

declare(strict_types=1);

use TranslationAudit\Actions\PurgeTranslationsFromFile;
use TranslationAudit\Results\Contracts\Result;

// var_export is banned as a debug function, but PurgeTranslationsFromFile uses it to write the PHP translation files.
arch()->preset()->php()->ignoring(PurgeTranslationsFromFile::class);

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
