<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command;
use TranslationAudit\Console\Commands\AuditTranslations;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    config(['translation-audit.supported_locales' => ['en', 'it']]);
});

describe('configuration', function (): void {
    it('fails when no scan path is configured', function (): void {
        config(['translation-audit.scan_paths' => []]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('Specify which paths to scan in the "scan_paths" config.')
            ->assertExitCode(Command::INVALID);
    });

    it('fails when no locale is configured', function (): void {
        config(['translation-audit.supported_locales' => []]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('The "supported_locales" config must be an array listing the app supported locales.')
            ->assertExitCode(Command::INVALID);
    });

    it('fails when a config value is not an array', function (string $key): void {
        config(["translation-audit.{$key}" => 'invalid']);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain("The \"{$key}\" config must be an array.")
            ->assertExitCode(Command::INVALID);
    })->with(['scan_paths', 'ignore_paths', 'ignore_links', 'ignore_locales', 'supported_locales']);

    it('fails when a config value contains something other than non-empty strings', function (string $key, mixed $value): void {
        config(["translation-audit.{$key}" => ['en', $value]]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain("The \"{$key}\" config must contain only non-empty strings.")
            ->assertExitCode(Command::INVALID);
    })->with(['scan_paths', 'ignore_paths', 'ignore_links', 'ignore_locales', 'supported_locales'])->with([
        'empty string' => '',
        'integer' => 1,
        'null' => null,
        'nested array' => [['it']],
    ]);

    it('succeeds when there are no files to scan', function (): void {
        artisan(AuditTranslations::class)
            ->expectsOutput('No missing translations found.')
            ->assertSuccessful();
    });
});

describe('heavy paths', function (): void {
    it('warns when a heavy path is set for scan', function (string $scan_path, Closure $path): void {
        config(['translation-audit.scan_paths' => [$scan_path]]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain("The '{$path()}' is set for scan. This may cause heavy resource usage and significantly slow down the audit.")
            ->assertSuccessful();
    })->with([
        'vendor' => ['vendor', fn (): string => base_path('vendor')],
        'node_modules' => ['node_modules', fn (): string => base_path('node_modules')],
        'storage' => ['storage', fn (): string => storage_path()],
    ]);

    it('does not warn for paths that are not heavy', function (string $scan_path): void {
        config(['translation-audit.scan_paths' => [$scan_path]]);

        artisan(AuditTranslations::class)
            ->doesntExpectOutputToContain('is set for scan')
            ->assertSuccessful();
    })->with([
        'app' => 'app/**/*.php',
        'views' => 'resources/views/**/*blade.php',
        'vendor subpath' => 'vendor/acme/**/*.php',
    ]);
});

describe('supported locales', function (): void {
    beforeEach(function (): void {
        putFile('app/Greeter.php', "<?php __('Hello');");
    });

    it('uses the configured locales when not auto', function (): void {
        populateLangDir(files: ['fr.json'], directories: ['es']);

        auditAsJson()
            ->expectsOutput(resultJson(['app/Greeter.php' => ['Hello' => ['en', 'it']]]))
            ->assertFailed();
    });

    it('detects the locales from the filesystem when configured as auto', function (): void {
        config(['translation-audit.supported_locales' => ['auto']]);
        populateLangDir(files: ['de.json', 'it.json'], directories: ['it', 'fr', 'vendor/some-package/es']);
        putFile('lang/README.md', '');
        putFile('lang/en.php', '<?php return [];');

        auditAsJson()
            ->expectsOutput(resultJson(['app/Greeter.php' => ['Hello' => ['de', 'fr', 'it']]]))
            ->assertFailed();
    });

    it('detects only the locales at the top level of the lang directory', function (): void {
        config(['translation-audit.supported_locales' => ['auto']]);
        populateLangDir(files: ['en.json', 'it/fr.json', 'vendor/some-package/es.json'], directories: ['it/de', 'vendor/some-package']);

        auditAsJson()
            ->expectsOutput(resultJson(['app/Greeter.php' => ['Hello' => ['en', 'it']]]))
            ->assertFailed();
    });

    it('does not audit ignored locales', function (): void {
        config(['translation-audit.ignore_locales' => ['en']]);

        auditAsJson()
            ->expectsOutput(resultJson(['app/Greeter.php' => ['Hello' => ['it']]]))
            ->assertFailed();
    });

    it('fails when auto detecting locales without a lang directory', function (): void {
        config(['translation-audit.supported_locales' => ['auto']]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('Unable to autodetect supported locales. The lang folder is missing.')
            ->assertExitCode(Command::INVALID);
    });

    it('fails when auto detecting locales in a lang directory without locales', function (): void {
        config(['translation-audit.supported_locales' => ['auto']]);
        populateLangDir(files: ['test.md'], directories: ['vendor']);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('Unable to autodetect locales in the "lang" folder.')
            ->assertExitCode(Command::INVALID);
    });
});

describe('ignored keys', function (): void {
    beforeEach(function (): void {
        putFile('app/Greeter.php', "<?php __('Hello'); __('Hi'); __('Bye');");
    });

    it('ignores the keys for every locale or only for the listed locales', function (): void {
        config(['translation-audit.ignore_keys' => ['Hello', 'Hi' => ['en']]]);

        auditAsJson()
            ->expectsOutput(resultJson(['app/Greeter.php' => ['Hi' => ['it'], 'Bye' => ['en', 'it']]]))
            ->assertFailed();
    });

    it('ignores a key for every locale when it is also listed with locales', function (): void {
        config(['translation-audit.ignore_keys' => ['Hi' => ['en'], 'Hello', 'Bye', 'Hi']]);

        artisan(AuditTranslations::class)
            ->expectsOutput('No missing translations found.')
            ->assertSuccessful();
    });

    it('fails when the ignore_keys config is not an array', function (): void {
        config(['translation-audit.ignore_keys' => 'Hello']);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('The "ignore_keys" config must be an array.')
            ->assertExitCode(Command::INVALID);
    });

    it('fails when the ignore_keys config contains an invalid entry', function (mixed $value): void {
        config(['translation-audit.ignore_keys' => $value]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('The "ignore_keys" config must contain only keys, or keys mapped to a list of locales.')
            ->assertExitCode(Command::INVALID);
    })->with([
        'empty key' => [['']],
        'integer key' => [[1]],
        'locales without key' => [[['en']]],
        'locale string' => [['Hi' => 'en']],
        'locales map' => [['Hi' => ['a' => 'en']]],
    ]);

    it('fails when a key in the ignore_keys config has invalid locales', function (mixed $locale): void {
        config(['translation-audit.ignore_keys' => ['Hi' => ['en', $locale]]]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('The locales of the "Hi" key in the "ignore_keys" config must be non-empty strings.')
            ->assertExitCode(Command::INVALID);
    })->with([
        'empty string' => '',
        'integer' => 1,
        'null' => null,
    ]);
});

describe('file selection', function (): void {
    it('scans only the files matching the scan paths', function (): void {
        putFile('resources/views/welcome.blade.php', "{{ __('Welcome') }}");
        putFile('app/Models/User.php', "<?php __('User');");
        putFile('app/notes.txt', "<?php __('Notes');");
        putFile('routes/web.php', "<?php __('Route');");

        auditAsJson()
            ->expectsOutput(resultJson([
                'app/Models/User.php' => ['User' => ['en', 'it']],
                'resources/views/welcome.blade.php' => ['Welcome' => ['en', 'it']],
            ]))
            ->assertFailed();
    });

    it('reports the files sorted by path', function (): void {
        $names = ['Zeta', 'Mu', 'Alpha', 'Omega', 'Beta', 'Kappa', 'Delta', 'Sigma', 'Gamma', 'Eta'];

        foreach ($names as $name) {
            putFile("app/{$name}.php", "<?php __('{$name}');");
        }

        sort($names);

        auditAsJson()
            ->expectsOutput(resultJson(collect($names)->mapWithKeys(fn (string $name): array => ["app/{$name}.php" => [$name => ['en', 'it']]])->all()))
            ->assertFailed();
    });

    it('skips the files matching the ignore paths', function (): void {
        config(['translation-audit.ignore_paths' => ['app/Legacy/**']]);
        putFile('app/Legacy/Old.php', "<?php __('Old');");
        putFile('app/New.php', "<?php __('New');");

        auditAsJson()
            ->expectsOutput(resultJson(['app/New.php' => ['New' => ['en', 'it']]]))
            ->assertFailed();
    });

    it('shows the scan progress', function (): void {
        putFile('app/First.php', '<?php');
        putFile('app/Second.php', '<?php');

        artisan(AuditTranslations::class)
            ->expectsOutputToContain(']   0% app/First.php')
            ->expectsOutputToContain('] 100%')
            ->assertSuccessful();
    });

    it('does not show the scan progress when there are no files to scan', function (): void {
        artisan(AuditTranslations::class)
            ->doesntExpectOutputToContain('0/0')
            ->assertSuccessful();
    });

    it('fails naming the file that cannot be parsed', function (): void {
        putFile('app/Broken.php', '<?php function (');

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('Unable to scan app/Broken.php: Syntax error')
            ->assertFailed();
    });
});

describe('symbolic links', function (): void {
    beforeEach(function (): void {
        putFile('shared/views/welcome.blade.php', "{{ __('Welcome') }}");
        putLink('shared/views', 'resources/views');
    });

    it('does not follow symbolic links by default', function (): void {
        artisan(AuditTranslations::class)
            ->expectsOutput('No missing translations found.')
            ->assertSuccessful();
    });

    describe('--follow-links option', function (): void {
        it('follows symbolic links with the follow links option', function (): void {
            auditAsJson(['--follow-links' => true])
                ->expectsOutput(resultJson(['resources/views/welcome.blade.php' => ['Welcome' => ['en', 'it']]]))
                ->assertFailed();
        });

        it('follows symbolic links with the follow links option without a value', function (): void {
            auditAsJson(['--follow-links' => null])
                ->expectsOutput(resultJson(['resources/views/welcome.blade.php' => ['Welcome' => ['en', 'it']]]))
                ->assertFailed();
        });

        it('follows symbolic links with the follow links option set to true', function (): void {
            auditAsJson(['--follow-links' => 'true'])
                ->expectsOutput(resultJson(['resources/views/welcome.blade.php' => ['Welcome' => ['en', 'it']]]))
                ->assertFailed();
        });

        it('does not follow symbolic links when the option overrides the config', function (mixed $value): void {
            config(['translation-audit.always_follow_links' => true]);

            artisan(AuditTranslations::class, ['--follow-links' => $value])
                ->expectsOutput('No missing translations found.')
                ->assertSuccessful();
        })->with([
            'false string' => 'false',
            'false boolean' => false,
        ]);

        it('fails when the follow links option is not a boolean', function (): void {
            artisan(AuditTranslations::class, ['--follow-links' => 'yes'])
                ->expectsOutputToContain('The --follow-links option accepts only true or false.')
                ->assertExitCode(Command::INVALID);
        });
    });

    describe('always_follow_links config', function (): void {
        it('follows symbolic links when the config always follows them', function (): void {
            config(['translation-audit.always_follow_links' => true]);

            auditAsJson()
                ->expectsOutput(resultJson(['resources/views/welcome.blade.php' => ['Welcome' => ['en', 'it']]]))
                ->assertFailed();
        });
    });

    describe('ignore_links config', function (): void {
        it('does not follow the symbolic links matching the ignore links', function (): void {
            config(['translation-audit.ignore_links' => ['app/Legacy*']]);
            putFile('shared/legacy/Old.php', "<?php __('Old');");
            putFile('shared/modern/New.php', "<?php __('New');");
            putLink('shared/legacy', 'app/LegacyModule');
            putLink('shared/modern', 'app/Module');

            auditAsJson(['--follow-links' => true])
                ->expectsOutput(resultJson([
                    'app/Module/New.php' => ['New' => ['en', 'it']],
                    'resources/views/welcome.blade.php' => ['Welcome' => ['en', 'it']],
                ]))
                ->assertFailed();
        });

        it('does not follow the symbolic links matching the ignore links by default', function (): void {
            putFile('shared/vendor/acme/Package.php', "<?php __('Package');");
            putLink('shared/vendor', 'vendor');
            config(['translation-audit.scan_paths' => ['vendor/**/*.php', 'resources/views/**/*blade.php']]);

            auditAsJson(['--follow-links' => true])
                ->expectsOutput(resultJson(['resources/views/welcome.blade.php' => ['Welcome' => ['en', 'it']]]))
                ->assertFailed();
        });
    });
});

describe('saving the result', function (): void {
    beforeEach(function (): void {
        config(['translation-audit.save_path' => base_path('audits')]);
        travelTo(Carbon::create(2026, 9, 30, 18, 30));
    });

    it('does not save the result by default', function (): void {
        putFile('app/Example.php', "<?php __('Hello');");

        artisan(AuditTranslations::class)
            ->doesntExpectOutputToContain('Audit result saved')
            ->assertFailed();

        expect(getAuditSavedFiles())->toBeEmpty();
    });

    describe('--save option', function (): void {
        it('saves the missing translations to a json file with the save option', function (): void {
            putJsonTranslations('it', ['Hello' => 'Ciao']);
            putFile('app/Example.php', "<?php __('Hello'); __('messages.welcome');");

            artisan(AuditTranslations::class, ['--save' => null])
                ->expectsOutputToContain('Audit result saved: '.base_path('audits').DIRECTORY_SEPARATOR.'translation-audit-30_Sep_2026_18_30-')
                ->assertFailed();

            $files = getAuditSavedFiles();

            expect($files)->toHaveCount(1)
                ->and($files[0])->toMatch('/^translation-audit-30_Sep_2026_18_30-[a-zA-Z0-9]{8}\.json$/')
                ->and(getFirstAuditSaveFileJsonContent())->toBe([
                    'app/Example.php' => [
                        'Hello' => ['en'],
                        'messages.welcome' => ['en', 'it'],
                    ],
                ]);
        });

        it('saves an empty result when no translation is missing', function (): void {
            artisan(AuditTranslations::class, ['--save' => true])
                ->expectsOutputToContain('Audit result saved')
                ->expectsOutput('No missing translations found.')
                ->assertSuccessful();

            expect(getAuditSavedFiles())->toHaveCount(1)
                ->and(getFirstAuditSaveFileJsonContent())->toBe([]);
        });

        it('does not save the result when the option overrides the config', function (mixed $value): void {
            config(['translation-audit.always_save' => true]);

            artisan(AuditTranslations::class, ['--save' => $value])
                ->doesntExpectOutputToContain('Audit result saved')
                ->assertSuccessful();

            expect(getAuditSavedFiles())->toBeEmpty();
        })->with([
            'false string' => 'false',
            'false boolean' => false,
        ]);

        it('fails when the save option is not a boolean', function (): void {
            artisan(AuditTranslations::class, ['--save' => '1'])
                ->expectsOutputToContain('The --save option accepts only true or false.')
                ->assertExitCode(Command::INVALID);

            expect(getAuditSavedFiles())->toBeEmpty();
        });
    });

    describe('always_save config', function (): void {
        it('saves the result when the config always saves it', function (): void {
            config(['translation-audit.always_save' => true]);

            artisan(AuditTranslations::class)
                ->expectsOutputToContain('Audit result saved')
                ->assertSuccessful();

            expect(getAuditSavedFiles())->toHaveCount(1);
        });
    });

    describe('--save-path option', function (): void {
        it('saves the result in the directory given by the save path option, creating it if missing', function (): void {
            artisan(AuditTranslations::class, ['--save' => true, '--save-path' => base_path('reports/nested/')])
                ->assertSuccessful();

            expect(getAuditSavedFiles())->toBeEmpty()
                ->and(getAuditSavedFiles('reports/nested'))->toHaveCount(1);
        });
    });

    describe('--save-name option and save_name config', function (): void {
        it('saves the result with the name given by the save name option', function (): void {
            artisan(AuditTranslations::class, ['--save' => true, '--save-name' => 'audit'])
                ->expectsOutputToContain('Audit result saved: '.base_path('audits').DIRECTORY_SEPARATOR.'audit.json')
                ->assertSuccessful();

            expect(getAuditSavedFiles())->toBe(['audit.json']);
        });

        it('saves the result with the name given by the config', function (): void {
            config(['translation-audit.save_name' => 'nightly']);

            artisan(AuditTranslations::class, ['--save' => true])->assertSuccessful();

            expect(getAuditSavedFiles())->toBe(['nightly.json']);
        });

        it('resolves the placeholders in the save name', function (string $name, string $pattern): void {
            artisan(AuditTranslations::class, ['--save' => true, '--save-name' => $name])->assertSuccessful();

            expect(getAuditSavedFiles())->toHaveCount(1)
                ->and(getAuditSavedFiles()[0])->toMatch($pattern);
        })->with([
            'now with format' => ['audit-{now:Y-m-d_H-i}', '/^audit-2026-09-30_18-30\.json$/'],
            'now without format' => ['audit-{now}', '/^audit-2026-09-30\.json$/'],
            'now with slashes in the format' => ['audit-{now:Y/m/d}', '/^audit-2026-09-30\.json$/'],
            'now with colons in the format' => ['audit-{now:H:i}', '/^audit-18-30\.json$/'],
            'now with colons in the formatted date' => ['audit-{now:c}', '/^audit-2026-09-30T18-30-00\+00-00\.json$/'],
            'random with length' => ['audit-{random:4}', '/^audit-[a-zA-Z0-9]{4}\.json$/'],
            'random without length' => ['audit-{random}', '/^audit-[a-zA-Z0-9]{8}\.json$/'],
            'many placeholders' => ['{now:Y}-{random:3}-{now:m}', '/^2026-[a-zA-Z0-9]{3}-09\.json$/'],
            'unknown placeholder' => ['audit-{unknown}', '/^audit-\{unknown\}\.json$/'],
        ]);
    });

    describe('--save-format option and save_format config', function (): void {
        it('saves the result in the format given by the config', function (): void {
            config(['translation-audit.save_format' => 'json', 'translation-audit.save_name' => 'audit']);

            artisan(AuditTranslations::class, ['--save' => true])->assertSuccessful();

            expect(getAuditSavedFiles())->toBe(['audit.json']);
        });

        it('fails when the save format is not supported', function (Closure $configure, array $options): void {
            $configure();

            artisan(AuditTranslations::class, ['--save' => true, ...$options])
                ->expectsOutputToContain("Invalid save format 'unsupported_format'. Supported formats: json")
                ->assertExitCode(Command::INVALID);

            expect(getAuditSavedFiles())->toBeEmpty();
        })->with([
            'option' => [fn (): null => null, ['--save-format' => 'unsupported_format']],
            'config' => [fn () => config(['translation-audit.save_format' => 'unsupported_format']), []],
        ]);
    });

    describe('save options validation', function (): void {
        it('does not validate the save options when the result is not saved', function (): void {
            artisan(AuditTranslations::class, ['--save-format' => 'unsupported_format', '--save-name' => ''])
                ->expectsOutput('No missing translations found.')
                ->assertSuccessful();
        });

        it('fails when a save option is empty', function (string $option): void {
            artisan(AuditTranslations::class, ['--save' => true, "--{$option}" => ''])
                ->expectsOutputToContain("The --{$option} option only accepts non-empty strings.")
                ->assertExitCode(Command::INVALID);

            expect(getAuditSavedFiles())->toBeEmpty();
        })->with(['save-format', 'save-path', 'save-name']);

        it('fails when a save config is not a string', function (string $key): void {
            config(["translation-audit.{$key}" => ['invalid']]);

            artisan(AuditTranslations::class, ['--save' => true])
                ->assertExitCode(Command::INVALID);

            expect(getAuditSavedFiles())->toBeEmpty();
        })->with(['save_format', 'save_path', 'save_name']);
    });
});

describe('translation detection', function (): void {
    it('detects the translation key of a call', function (string $call): void {
        putFile('app/Example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Lang;\nuse Illuminate\\Support\\Facades\\Lang as Translations;\n\n{$call};");

        auditAsJson()
            ->expectsOutput(resultJson(['app/Example.php' => ['messages.welcome' => ['en', 'it']]]))
            ->assertFailed();
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
    ]);

    it('ignores calls without a static translation key', function (string $call): void {
        putFile('app/Example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Lang;\n\n{$call};");

        artisan(AuditTranslations::class)
            ->expectsOutput('No missing translations found.')
            ->assertSuccessful();
    })->with([
        'variable key' => '__($key)',
        'interpolated key' => '__("messages.{$key}")',
        'concatenated key' => "__('messages.'.\$key)",
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

        auditAsJson()
            ->expectsOutput(resultJson(['resources/views/welcome.blade.php' => ['messages.welcome' => ['en', 'it']]]))
            ->assertFailed();
    })->with([
        'echo' => "<h1>{{ __('messages.welcome') }}</h1>",
        'raw echo' => "<h1>{!! trans('messages.welcome') !!}</h1>",
        '@lang' => "<h1>@lang('messages.welcome')</h1>",
        '@choice' => "<h1>@choice('messages.welcome', 2)</h1>",
        'php block' => "@php \$title = __('messages.welcome'); @endphp",
    ]);
});

describe('missing translations', function (): void {
    it('reports only the locales missing a translation', function (): void {
        putJsonTranslations('en', ['Hello' => 'Hello']);
        putFile('lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto'];");
        putFile('app/Example.php', "<?php __('Hello'); __('messages.welcome'); __('messages.goodbye');");

        auditAsJson()
            ->expectsOutput(resultJson(['app/Example.php' => [
                'Hello' => ['it'],
                'messages.welcome' => ['en'],
                'messages.goodbye' => ['en', 'it'],
            ]]))
            ->assertFailed();
    });

    it('reports nothing when every key is translated in every locale', function (): void {
        putJsonTranslations('en', ['Hello' => 'Hello']);
        putJsonTranslations('it', ['Hello' => 'Ciao']);
        putFile('app/Example.php', "<?php __('Hello');");

        artisan(AuditTranslations::class)
            ->expectsOutput('No missing translations found.')
            ->assertSuccessful();
    });

    it('reports a key used many times in the same file once', function (): void {
        putFile('app/Example.php', "<?php __('Hello'); trans('Hello'); Lang::get('Hello');");

        auditAsJson()
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->assertFailed();
    });

    it('groups the missing keys by file', function (): void {
        putFile('app/First.php', "<?php __('One'); __('Two');");
        putFile('app/Second.php', "<?php __('One');");
        putJsonTranslations('it', ['One' => 'Uno', 'Two' => 'Due']);

        auditAsJson()
            ->expectsOutput(resultJson([
                'app/First.php' => ['One' => ['en'], 'Two' => ['en']],
                'app/Second.php' => ['One' => ['en']],
            ]))
            ->assertFailed();
    });
});

dataset('display formats', [
    'list' => ['list', 'EN, IT  Hello'],
    'table' => ['table', '| app/Example.php | Hello | EN, IT          |'],
    'json' => ['json', '{"app/Example.php":{"Hello":["en","it"]}}'],
]);

describe('display format', function (): void {
    beforeEach(function (): void {
        putFile('app/Example.php', "<?php __('Hello');");
    });

    it('prints the result as a list by default', function (): void {
        artisan(AuditTranslations::class)
            ->expectsOutput('  app/Example.php')
            ->expectsOutput('    EN, IT  Hello')
            ->expectsOutput('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    });

    it('prints the result in the format given by the option', function (string $format, string $output): void {
        artisan(AuditTranslations::class, ['--display-format' => $format])
            ->expectsOutputToContain($output)
            ->expectsOutputToContain('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    })->with('display formats');

    it('prints the result in the format given by the config', function (string $format, string $output): void {
        config(['translation-audit.display_format' => $format]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain($output)
            ->expectsOutputToContain('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    })->with('display formats');

    it('fails when the display format is not supported', function (Closure $configure, array $options): void {
        $configure();

        artisan(AuditTranslations::class, $options)
            ->expectsOutputToContain("Invalid display format 'unsupported_format'. Supported formats: json, list, table")
            ->assertExitCode(Command::INVALID);
    })->with([
        'option' => [fn (): null => null, ['--display-format' => 'unsupported_format']],
        'config' => [fn () => config(['translation-audit.display_format' => 'unsupported_format']), []],
    ]);
});
