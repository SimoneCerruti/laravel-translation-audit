<?php

declare(strict_types=1);

use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\TranslationCalls;

it('has no position for the calls not in the config', function (): void {
    $translation_calls = TranslationCalls::fromConfig([]);

    expect($translation_calls->functionKeyPosition('t'))->toBeNull()
        ->and($translation_calls->staticMethodKeyPosition('App\Support\Translator', 'translate'))->toBeNull();
});

it('takes the first argument as the key of a listed call', function (): void {
    $translation_calls = TranslationCalls::fromConfig(['t', 'App\Support\Translator::translate']);

    expect($translation_calls->functionKeyPosition('t'))->toBe(0)
        ->and($translation_calls->staticMethodKeyPosition('App\Support\Translator', 'translate'))->toBe(0);
});

it('takes the argument at the mapped position as the key of a call', function (): void {
    $translation_calls = TranslationCalls::fromConfig(['t_for' => 1, 'App\Support\Translator::translateFor' => 2]);

    expect($translation_calls->functionKeyPosition('t_for'))->toBe(1)
        ->and($translation_calls->staticMethodKeyPosition('App\Support\Translator', 'translateFor'))->toBe(2);
});

it('matches the names regardless of their case and leading backslash', function (): void {
    $translation_calls = TranslationCalls::fromConfig(['\App\Support\T', '\App\Support\Translator::Translate']);

    expect($translation_calls->functionKeyPosition('app\support\t'))->toBe(0)
        ->and($translation_calls->functionKeyPosition('\APP\SUPPORT\T'))->toBe(0)
        ->and($translation_calls->staticMethodKeyPosition('\app\support\TRANSLATOR', 'translate'))->toBe(0);
});

it('matches a function by the first of its candidate names in the config', function (): void {
    $translation_calls = TranslationCalls::fromConfig(['t' => 1, 'App\Support\t' => 2]);

    expect($translation_calls->functionKeyPosition('App\Support\t', 't'))->toBe(2)
        ->and($translation_calls->functionKeyPosition('App\Http\t', 't'))->toBe(1)
        ->and($translation_calls->functionKeyPosition('App\Http\t'))->toBeNull();
});

it('fails on an entry that is neither a call nor a call mapped to a position', function (mixed $config): void {
    expect(fn (): TranslationCalls => TranslationCalls::fromConfig($config))
        ->toThrow(InvalidConfigException::class, 'The "translation_calls" config must list the function names and the "Class::method" static methods, or map them to the position of the argument holding the translation key, starting from 0.');
})->with([
    'integer' => [[1]],
    'null' => [[null]],
    'nested array' => [[['t']]],
    'negative position' => [['t' => -1]],
    'string position' => [['t' => '1']],
]);

it('fails on an entry that is not a function name nor a static method', function (string $entry): void {
    expect(fn (): TranslationCalls => TranslationCalls::fromConfig([$entry]))
        ->toThrow(InvalidConfigException::class, "The \"{$entry}\" entry of the \"translation_calls\" config must be a function name");
})->with([
    'empty string' => '',
    'instance method' => 'App\Support\Translator->translate',
    'missing method' => 'App\Support\Translator::',
    'missing class' => '::translate',
    'namespaced method' => 'App\Support\Translator::Other\translate',
    'two separators' => 'App\Support\Translator::translate::key',
    'invalid characters' => 'translate key',
    'trailing backslash' => 'App\Support\\',
]);
