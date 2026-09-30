<?php

declare(strict_types=1);

use TranslationAudit\Actions\FormatLocales;

it('formats the locales uppercase and comma separated', function (array $locales, string $expected): void {
    expect(app(FormatLocales::class)->handle($locales))->toBe($expected);
})->with([
    'one locale' => [['it'], 'IT'],
    'many locales' => [['en', 'it', 'pt_BR'], 'EN, IT, PT_BR'],
]);
