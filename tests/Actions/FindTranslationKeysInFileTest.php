<?php

declare(strict_types=1);

use Illuminate\Foundation\AliasLoader;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\FindTranslationKeysInFile;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Support\TranslationCalls;

/**
 * @param  array<array-key, mixed>  $translation_calls  The translation_calls config.
 * @return list<non-falsy-string|DynamicTranslationKey>
 */
function findTranslationKeysInFile(string $relative_path, array $translation_calls = []): array {
    return app(FindTranslationKeysInFile::class)->handle(new SplFileInfo(base_path($relative_path), dirname($relative_path), $relative_path), TranslationCalls::fromConfig($translation_calls));
}

it('returns the key of each translation call, keeping the duplicates', function (): void {
    putFile('app/Example.php', "<?php __('First'); Lang::get('Second'); trans()->get('Third'); __('First');");

    expect(findTranslationKeysInFile('app/Example.php'))->toEqualCanonicalizing(['First', 'First', 'Second', 'Third']);
});

it('detects the translation key of a call', function (string $call): void {
    putFile('app/Example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Lang;\nuse Illuminate\\Support\\Facades\\Lang as Translations;\n\n{$call};");

    expect(findTranslationKeysInFile('app/Example.php'))->toBe(['messages.welcome']);
})->with([
    '__()' => "__('messages.welcome')",
    'trans()' => "trans('messages.welcome', ['name' => 'Taylor'])",
    'trans_choice()' => "trans_choice('messages.welcome', 2)",
    'Lang::get()' => "Lang::get('messages.welcome')",
    'Lang::string()' => "Lang::string('messages.welcome')",
    'Lang::array()' => "Lang::array('messages.welcome')",
    'Lang::choice()' => "Lang::choice('messages.welcome', 2)",
    'Lang::has()' => "Lang::has('messages.welcome')",
    'Lang::hasForLocale()' => "Lang::hasForLocale('messages.welcome', 'en')",
    'fully qualified Lang facade' => "\\Illuminate\\Support\\Facades\\Lang::get('messages.welcome')",
    'aliased Lang facade' => "Translations::get('messages.welcome')",
    'trans()->get()' => "trans()->get('messages.welcome')",
    "app('translator')->get()" => "app('translator')->get('messages.welcome')",
    'double quoted string' => '__("messages.welcome")',
    'concatenated strings' => "__('messages.'.'welcome')",
    'named key argument' => "__(key: 'messages.welcome')",
    'named key argument after other named arguments' => "trans(replace: ['name' => 'Taylor'], key: 'messages.welcome')",
    'named key argument of the Lang facade' => "Lang::choice(number: 2, key: 'messages.welcome')",
]);

it('detects the dynamic translation key of a call, made of the static texts around the expressions', function (string $call, array $segments): void {
    putFile('app/Example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Lang;\n\n{$call};");

    expect(findTranslationKeysInFile('app/Example.php'))->toEqual([new DynamicTranslationKey($segments)]);
})->with([
    'interpolated key' => ['__("messages.{$key}")', ['messages.', '']],
    'interpolated key with a suffix' => ['__("messages.{$key}.label")', ['messages.', '.label']],
    'interpolated key with only a static suffix' => ['__("{$group}.title")', ['', '.title']],
    'interpolated simple variable' => ['__("messages.$key")', ['messages.', '']],
    'interpolated property' => ['__("messages.{$method->value}")', ['messages.', '']],
    'interpolated key with consecutive expressions' => ['__("messages.{$group}{$key}.label")', ['messages.', '.label']],
    'concatenated key' => ["__('messages.'.\$key)", ['messages.', '']],
    'concatenated key with a suffix' => ["__('messages.'.\$key.'.label')", ['messages.', '.label']],
    'concatenated call' => ["__('messages.'.strtolower(\$key))", ['messages.', '']],
    'concatenated interpolated key' => ["__(\"messages.{\$group}\".'.label')", ['messages.', '.label']],
    'Lang facade' => ['Lang::get("messages.{$key}")', ['messages.', '']],
    'trans_choice()' => ['trans_choice("messages.{$key}", 2)', ['messages.', '']],
]);

it('ignores calls without a static translation key', function (string $call): void {
    putFile('app/Example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Lang;\n\n{$call};");

    expect(findTranslationKeysInFile('app/Example.php'))->toBeEmpty();
})->with([
    'variable key' => '__($key)',
    'interpolated key without static text' => '__("{$group}{$key}")',
    'concatenated key without static text' => '__($group.$key)',
    'empty key' => "__('')",
    'no arguments' => '__()',
    'first class callable' => '__(...)',
    'translator without arguments' => 'trans()',
    'unrelated function' => "strtoupper('messages.welcome')",
    'dynamic function name' => "\$fn('messages.welcome')",
    'non translator Lang method' => "Lang::setLocale('it')",
    'non translator translator method' => "trans()->setLocale('it')",
    'other facade' => "\\Illuminate\\Support\\Facades\\Config::get('messages.welcome')",
    'other service' => "app('config')->get('messages.welcome')",
    'dynamic translator method' => "trans()->{\$method}('messages.welcome')",
    'first class callable translator method' => 'trans()->get(...)',
    'named arguments without the key' => "__(replace: ['name' => 'Taylor'])",
]);

it('detects translation keys in blade views', function (string $blade): void {
    putFile('resources/views/welcome.blade.php', $blade);

    expect(findTranslationKeysInFile('resources/views/welcome.blade.php'))->toBe(['messages.welcome']);
})->with([
    'echo' => "<h1>{{ __('messages.welcome') }}</h1>",
    'raw echo' => "<h1>{!! trans('messages.welcome') !!}</h1>",
    '@lang' => "<h1>@lang('messages.welcome')</h1>",
    '@choice' => "<h1>@choice('messages.welcome', 2)</h1>",
    'php block' => "@php \$title = __('messages.welcome'); @endphp",
]);

it('detects dynamic translation keys in blade views', function (): void {
    putFile('resources/views/welcome.blade.php', '<h1>{{ __("messages.{$key}") }}</h1><p>@lang("messages.$key")</p>');

    expect(findTranslationKeysInFile('resources/views/welcome.blade.php'))->toEqual([
        new DynamicTranslationKey(['messages.', '']),
        new DynamicTranslationKey(['messages.', '']),
    ]);
});

describe('custom translation calls', function (): void {
    beforeEach(function (): void {
        AliasLoader::getInstance()->alias('CustomTranslator', 'App\\Support\\Translator');
    });

    it('detects the translation key of a custom call', function (string $code, array $translation_calls): void {
        putFile('app/Example.php', "<?php\n\n{$code};");

        expect(findTranslationKeysInFile('app/Example.php', $translation_calls))->toBe(['messages.welcome']);
    })->with([
        'global function' => ["t('messages.welcome')", ['t']],
        'global function with a different case' => ["T('messages.welcome')", ['t']],
        'global function called in a namespace' => ["namespace App\\Http;\n\nt('messages.welcome')", ['t']],
        'namespaced function called in its namespace' => ["namespace App\\Support;\n\nt('messages.welcome')", ['App\\Support\\t']],
        'imported namespaced function' => ["use function App\\Support\\t;\n\nt('messages.welcome')", ['App\\Support\\t']],
        'fully qualified function with a leading backslash in the config' => ["\\App\\Support\\t('messages.welcome')", ['\\App\\Support\\t']],
        'imported static method' => ["use App\\Support\\Translator;\n\nTranslator::translate('messages.welcome')", ['App\\Support\\Translator::translate']],
        'static method with a different case' => ["\\App\\Support\\translator::TRANSLATE('messages.welcome')", ['App\\Support\\Translator::translate']],
        'aliased static method' => ["CustomTranslator::translate('messages.welcome')", ['App\\Support\\Translator::translate']],
        'Lang facade macro' => ["use Illuminate\\Support\\Facades\\Lang;\n\nLang::customTranslate('messages.welcome')", ['Illuminate\\Support\\Facades\\Lang::customTranslate']],
        'Lang alias macro' => ["Lang::customTranslate('messages.welcome')", ['Illuminate\\Support\\Facades\\Lang::customTranslate']],
        'key at a position' => ["t_for('it', 'messages.welcome')", ['t_for' => 1]],
        'static method key at a position' => ["\\App\\Support\\Translator::translateFor('it', 'messages.welcome')", ['App\\Support\\Translator::translateFor' => 1]],
    ]);

    it('detects the dynamic translation key of a custom call', function (): void {
        putFile('app/Example.php', '<?php t("messages.{$key}");');

        expect(findTranslationKeysInFile('app/Example.php', ['t']))->toEqual([new DynamicTranslationKey(['messages.', ''])]);
    });

    it('detects the translation key of a custom call in blade views', function (): void {
        putFile('resources/views/welcome.blade.php', "<h1>{{ t('messages.title') }}</h1><p>{{ CustomTranslator::translate('messages.welcome') }}</p>");

        expect(findTranslationKeysInFile('resources/views/welcome.blade.php', ['t', 'App\\Support\\Translator::translate']))->toBe(['messages.title', 'messages.welcome']);
    });

    it('ignores the custom calls without a static key at the position', function (string $code, array $translation_calls): void {
        putFile('app/Example.php', "<?php\n\n{$code};");

        expect(findTranslationKeysInFile('app/Example.php', $translation_calls))->toBeEmpty();
    })->with([
        'function not in the config' => ["t('messages.welcome')", []],
        'namespaced function not in the config' => ["use function App\\Support\\t;\n\nt('messages.welcome')", ['t']],
        'static method of another class' => ["\\App\\Support\\Other::translate('messages.welcome')", ['App\\Support\\Translator::translate']],
        'other static method' => ["\\App\\Support\\Translator::locale('messages.welcome')", ['App\\Support\\Translator::translate']],
        'unqualified class not imported' => ["Translator::translate('messages.welcome')", ['App\\Support\\Translator::translate']],
        'instance method' => ["\$translator->translate('messages.welcome')", ['App\\Support\\Translator::translate']],
        'missing argument at the position' => ["t_for('messages.welcome')", ['t_for' => 1]],
        'named argument' => ["t_for(key: 'messages.welcome', locale: 'it')", ['t_for' => 1]],
        'unpacked argument' => ['t(...$arguments)', ['t']],
        'first class callable' => ['t(...)', ['t']],
    ]);
});
