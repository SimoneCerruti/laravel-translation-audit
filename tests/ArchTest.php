<?php

declare(strict_types=1);

use TranslationAudit\Results\Contracts\Result;

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
