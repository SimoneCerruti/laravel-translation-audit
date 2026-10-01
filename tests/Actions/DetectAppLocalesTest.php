<?php

declare(strict_types=1);

use TranslationAudit\Actions\DetectAppLocales;
use TranslationAudit\Exceptions\InvalidConfigException;

it('detects the locales from the json files and the directories of the lang directory, sorted by name', function (): void {
    populateLangDir(files: ['de.json', 'it.json'], directories: ['it', 'fr', 'vendor/some-package/es']);
    putFile('lang/README.md', '');
    putFile('lang/en.php', '<?php return [];');

    expect(app(DetectAppLocales::class)->handle())->toBe(['de', 'fr', 'it']);
});

it('detects only the locales at the top level of the lang directory', function (): void {
    populateLangDir(files: ['en.json', 'it/fr.json', 'vendor/some-package/es.json'], directories: ['it/de', 'vendor/some-package']);

    expect(app(DetectAppLocales::class)->handle())->toBe(['en', 'it']);
});

it('fails without a lang directory', function (): void {
    app(DetectAppLocales::class)->handle();
})->throws(InvalidConfigException::class, 'Unable to autodetect supported locales. The lang folder is missing.');

it('fails when the lang directory has no locales', function (): void {
    populateLangDir(files: ['test.md'], directories: ['vendor']);

    app(DetectAppLocales::class)->handle();
})->throws(InvalidConfigException::class, 'Unable to autodetect locales in the "lang" folder.');
