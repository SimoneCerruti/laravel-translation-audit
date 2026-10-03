<?php

declare(strict_types=1);

use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Actions\FindTranslationKeysInFile;
use TranslationAudit\Data\DynamicTranslationKey;

/** @return list<non-falsy-string|DynamicTranslationKey> */
function findTranslationKeysInFile(string $relative_path): array {
    return app(FindTranslationKeysInFile::class)->handle(new SplFileInfo(base_path($relative_path), dirname($relative_path), $relative_path));
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
