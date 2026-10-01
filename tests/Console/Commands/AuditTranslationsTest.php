<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Enums\SaveFormat;

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
    })->with(['scan_paths', 'ignore_paths', 'ignore_links', 'ignore_locales', 'supported_locales', 'unused_ignore_paths']);

    it('fails when a config value contains something other than non-empty strings', function (string $key, mixed $value): void {
        config(["translation-audit.{$key}" => ['en', $value]]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain("The \"{$key}\" config must contain only non-empty strings.")
            ->assertExitCode(Command::INVALID);
    })->with(['scan_paths', 'ignore_paths', 'ignore_links', 'ignore_locales', 'supported_locales', 'unused_ignore_paths'])->with([
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

        artisan(AuditTranslations::class, ['--ansi' => true])
            ->expectsOutputToContain(']   0% app/First.php')
            ->expectsOutputToContain('] 100%')
            ->assertSuccessful();
    });

    it('hides the scan progress with the no-progress option', function (?string $value): void {
        putFile('app/First.php', '<?php');

        artisan(AuditTranslations::class, ['--no-progress' => $value, '--ansi' => true])
            ->doesntExpectOutputToContain('app/First.php')
            ->doesntExpectOutputToContain('100%')
            ->assertSuccessful();
    })->with([null, 'true']);

    it('hides the scan progress when the progress bar is disabled in the config', function (): void {
        config(['translation-audit.disable_progress_bar' => true]);
        putFile('app/First.php', '<?php');

        artisan(AuditTranslations::class, ['--ansi' => true])
            ->doesntExpectOutputToContain('app/First.php')
            ->assertSuccessful();
    });

    it('shows the scan progress when the no-progress option overrides the config', function (): void {
        config(['translation-audit.disable_progress_bar' => true]);
        putFile('app/First.php', '<?php');

        artisan(AuditTranslations::class, ['--no-progress' => 'false', '--ansi' => true])
            ->expectsOutputToContain(']   0% app/First.php')
            ->assertSuccessful();
    });

    it('shows the scan progress on the error output, keeping the standard output for the result', function (): void {
        putFile('app/Example.php', "<?php __('Hello');");

        $result = auditWithSeparateOutputs(['--display-format' => 'json'], decorated_error_output: true);

        expect($result['output'])->toBe(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]).PHP_EOL)
            ->and($result['error_output'])->toContain('] 100%');
    });

    it('hides the scan progress when the error output is not a terminal', function (): void {
        config(['translation-audit.disable_progress_bar' => false]);
        putFile('app/Example.php', "<?php __('Hello');");

        $result = auditWithSeparateOutputs(['--no-progress' => 'false']);

        expect($result['error_output'])->not->toContain('%')
            ->and($result['output'])->not->toContain('%');
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
                    'missing' => [
                        'app/Example.php' => [
                            'Hello' => ['en'],
                            'messages.welcome' => ['en', 'it'],
                        ],
                    ],
                ]);
        });

        it('saves an empty result when no translation is missing', function (): void {
            artisan(AuditTranslations::class, ['--save' => true])
                ->expectsOutputToContain('Audit result saved')
                ->expectsOutput('No missing translations found.')
                ->assertSuccessful();

            expect(getAuditSavedFiles())->toHaveCount(1)
                ->and(getFirstAuditSaveFileJsonContent())->toBe(['missing' => []]);
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
        it('saves the result in the format given by the config', function (SaveFormat|string $format): void {
            config(['translation-audit.save_format' => $format, 'translation-audit.save_name' => 'audit']);

            artisan(AuditTranslations::class, ['--save' => true])->assertSuccessful();

            expect(getAuditSavedFiles())->toBe(['audit.json']);
        })->with([
            'enum case' => [SaveFormat::Json],
            'value' => ['json'],
        ]);

        it('saves the result in the format given by the option', function (): void {
            config(['translation-audit.save_name' => 'audit']);

            artisan(AuditTranslations::class, ['--save' => true, '--save-format' => 'json'])->assertSuccessful();

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

describe('unused translations', function (): void {
    beforeEach(function (): void {
        putJsonTranslations('en', ['Hello' => 'Hello', 'Bye' => 'Bye']);
        putJsonTranslations('it', ['Hello' => 'Ciao']);
        putFile('lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto', 'old' => 'Vecchio', 'nested' => ['used' => 'Usato', 'unused' => 'Inutilizzato', 'empty' => []]];");
        putFile('lang/it/admin/users.php', "<?php return ['title' => 'Utenti'];");
        putFile('lang/it/validation.php', "<?php return ['required' => 'Obbligatorio'];");
        putFile('app/Example.php', "<?php __('Hello'); __('messages.welcome'); __('messages.nested.used');");
    });

    it('does not audit the unused translations by default', function (): void {
        auditAsJson()
            ->expectsOutput(resultJson(['app/Example.php' => ['messages.welcome' => ['en'], 'messages.nested.used' => ['en']]]))
            ->doesntExpectOutputToContain('unused')
            ->assertFailed();
    });

    it('reports the unused translations grouped by locale and translation file', function (?string $value): void {
        auditAsJson(['--unused' => $value])
            ->expectsOutput(resultJson(
                ['app/Example.php' => ['messages.welcome' => ['en'], 'messages.nested.used' => ['en']]],
                [
                    'en' => ['lang/en.json' => ['Bye' => 'Bye']],
                    'it' => [
                        'lang/it/admin/users.php' => ['admin/users.title' => 'Utenti'],
                        'lang/it/messages.php' => ['messages.old' => 'Vecchio', 'messages.nested.unused' => 'Inutilizzato'],
                    ],
                ],
            ))
            ->assertFailed();
    })->with([null, 'true']);

    it('reports the unused translations when enabled in the config', function (): void {
        config(['translation-audit.audit_unused' => true]);

        auditAsJson()
            ->expectsOutputToContain('"unused":{"en":{"lang/en.json":{"Bye":"Bye"}}')
            ->assertFailed();
    });

    it('does not audit the unused translations when the option overrides the config', function (): void {
        config(['translation-audit.audit_unused' => true]);

        auditAsJson(['--unused' => 'false'])
            ->doesntExpectOutputToContain('unused')
            ->assertFailed();
    });

    it('fails when only unused translations are found', function (): void {
        putJsonTranslations('en', ['Hello' => 'Hello']);
        putFile('lang/en/messages.php', "<?php return ['welcome' => 'Welcome', 'nested' => ['used' => 'Used']];");
        config(['translation-audit.supported_locales' => ['en']]);

        auditAsJson(['--unused' => true])
            ->expectsOutput(resultJson([], []))
            ->expectsOutput('No missing or unused translations found.')
            ->assertSuccessful();

        putJsonTranslations('en', ['Hello' => 'Hello', 'Bye' => 'Bye']);

        auditAsJson(['--unused' => true])
            ->expectsOutput(resultJson([], ['en' => ['lang/en.json' => ['Bye' => 'Bye']]]))
            ->expectsOutput('Found 1 unused key in 1 translation file.')
            ->doesntExpectOutputToContain('missing translations in')
            ->assertFailed();
    });

    it('does not report the translations of the files matching the unused ignore paths', function (): void {
        config(['translation-audit.unused_ignore_paths' => ['lang/*/messages.php', 'lang/*/admin/*.php', 'lang/*/validation.php', 'lang/en.json']]);

        auditAsJson(['--unused' => true])
            ->expectsOutputToContain('"unused":{}')
            ->assertFailed();
    });

    it('reports the translations of the files Laravel uses when they are not ignored', function (): void {
        config(['translation-audit.unused_ignore_paths' => []]);

        auditAsJson(['--unused' => true])
            ->expectsOutputToContain('"lang/it/validation.php":{"validation.required":"Obbligatorio"}')
            ->assertFailed();
    });

    it('does not report the ignored locales and keys', function (): void {
        config(['translation-audit.ignore_locales' => ['en'], 'translation-audit.ignore_keys' => ['messages.old', 'admin/users.title' => ['it']]]);

        auditAsJson(['--unused' => true])
            ->expectsOutputToContain('"unused":{"it":{"lang/it/messages.php":{"messages.nested.unused":"Inutilizzato"}}}')
            ->assertFailed();
    });

    it('prints the unused translations in every display format', function (string $format, string $output): void {
        artisan(AuditTranslations::class, ['--unused' => true, '--display-format' => $format])
            ->expectsOutputToContain($output)
            ->assertFailed();
    })->with([
        'list' => ['list', 'Unused translations'],
        'table' => ['table', '| IT     | lang/it/admin/users.php | admin/users.title      | Utenti       |'],
    ]);

    it('summarizes the missing and the unused translations', function (): void {
        artisan(AuditTranslations::class, ['--unused' => true])
            ->expectsOutput('Found 2 keys with missing translations in 1 file.')
            ->expectsOutput('Found 4 unused keys in 3 translation files.')
            ->assertFailed();
    });

    it('saves the unused translations', function (): void {
        config(['translation-audit.save_path' => base_path('audits')]);

        artisan(AuditTranslations::class, ['--unused' => true, '--save' => true])->assertFailed();

        expect(getFirstAuditSaveFileJsonContent())->toHaveKey('unused.en', ['lang/en.json' => ['Bye' => 'Bye']]);
    });

    it('fails naming the translation file that cannot be read', function (string $path, string $contents, string $error): void {
        putFile('app/Example.php', '<?php');
        putFile($path, $contents);

        artisan(AuditTranslations::class, ['--unused' => true])
            ->expectsOutputToContain("Unable to read {$path}: {$error}")
            ->assertFailed();
    })->with([
        'invalid json' => ['lang/en.json', '{', 'Syntax error'],
        'invalid php' => ['lang/en/broken.php', '<?php return [', "Unclosed '['"],
    ]);

    it('fails when the unused option is not a boolean', function (): void {
        artisan(AuditTranslations::class, ['--unused' => 'maybe'])
            ->expectsOutputToContain('The --unused option accepts only true or false.')
            ->assertExitCode(Command::INVALID);
    });
});

dataset('display formats', [
    'list' => ['list', 'EN, IT  Hello'],
    'table' => ['table', '| app/Example.php | Hello | EN, IT          |'],
    'json' => ['json', '{"missing":{"app/Example.php":{"Hello":["en","it"]}}}'],
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

    it('prints the result in the format given by the config', function (string $format, string $output, bool $as_enum): void {
        config(['translation-audit.display_format' => $as_enum ? DisplayFormat::from($format) : $format]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain($output)
            ->expectsOutputToContain('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    })->with('display formats')->with([
        'enum case' => [true],
        'value' => [false],
    ]);

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

describe('agent output', function (): void {
    beforeEach(function (): void {
        putFile('app/Example.php', "<?php __('Hello');");
    });

    it('prints only the json result', function (?string $value): void {
        artisan(AuditTranslations::class, ['--for-agent' => $value, '--ansi' => true])
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->doesntExpectOutputToContain('%')
            ->doesntExpectOutputToContain('Found')
            ->assertFailed();
    })->with([null, 'true']);

    it('prints an empty json object when no translation is missing', function (): void {
        config(['translation-audit.supported_locales' => ['en']]);
        putFile('lang/en.json', '{"Hello": "Hello"}');

        artisan(AuditTranslations::class, ['--for-agent' => true])
            ->expectsOutput(resultJson([]))
            ->doesntExpectOutputToContain('No missing translations found.')
            ->assertSuccessful();
    });

    it('prints the empty json object without styling', function (): void {
        config(['translation-audit.supported_locales' => ['en']]);
        putFile('lang/en.json', '{"Hello": "Hello"}');

        artisan(AuditTranslations::class, ['--for-agent' => true, '--ansi' => true])
            ->expectsOutput(resultJson([]))
            ->assertSuccessful();
    });

    it('saves the result without printing where', function (): void {
        config(['translation-audit.save_path' => base_path('audits')]);

        artisan(AuditTranslations::class, ['--for-agent' => true, '--save' => true])
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->doesntExpectOutputToContain('Audit result saved')
            ->assertFailed();

        expect(getAuditSavedFiles())->toHaveCount(1)
            ->and(getFirstAuditSaveFileJsonContent())->toBe(['missing' => ['app/Example.php' => ['Hello' => ['en', 'it']]]]);
    });

    it('saves an empty result printing only the empty json object', function (): void {
        config(['translation-audit.supported_locales' => ['en'], 'translation-audit.save_path' => base_path('audits')]);
        putFile('lang/en.json', '{"Hello": "Hello"}');

        artisan(AuditTranslations::class, ['--for-agent' => true, '--save' => true])
            ->expectsOutput(resultJson([]))
            ->doesntExpectOutputToContain('Audit result saved')
            ->assertSuccessful();

        expect(getAuditSavedFiles())->toHaveCount(1)
            ->and(getFirstAuditSaveFileJsonContent())->toBe(['missing' => []]);
    });

    it('does not warn when a heavy path is set for scan', function (): void {
        config(['translation-audit.scan_paths' => ['vendor']]);

        artisan(AuditTranslations::class, ['--for-agent' => true])
            ->doesntExpectOutputToContain('is set for scan')
            ->assertSuccessful();
    });

    it('prints the result as usual when the option is false', function (): void {
        artisan(AuditTranslations::class, ['--for-agent' => 'false'])
            ->expectsOutput('    EN, IT  Hello')
            ->expectsOutput('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    });

    it('fails when the for-agent option is not a boolean', function (): void {
        artisan(AuditTranslations::class, ['--for-agent' => 'maybe'])
            ->expectsOutputToContain('The --for-agent option accepts only true or false.')
            ->assertExitCode(Command::INVALID);
    });

    it('prints the json result whatever the display format', function (Closure $configure, array $options): void {
        $configure();

        artisan(AuditTranslations::class, ['--for-agent' => true, ...$options])
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->assertFailed();
    })->with([
        'option' => [fn (): null => null, ['--display-format' => 'table']],
        'config' => [fn () => config(['translation-audit.display_format' => 'table']), []],
    ]);

    it('hides the progress bar and the summary whatever the options', function (): void {
        artisan(AuditTranslations::class, ['--for-agent' => true, '--no-progress' => 'false', '--no-summary' => 'false', '--ansi' => true])
            ->doesntExpectOutputToContain('%')
            ->doesntExpectOutputToContain('Found')
            ->assertFailed();
    });

    it('hides the progress bar and the summary whatever the config', function (): void {
        config(['translation-audit.disable_progress_bar' => false, 'translation-audit.disable_summary' => false]);

        artisan(AuditTranslations::class, ['--for-agent' => true, '--ansi' => true])
            ->doesntExpectOutputToContain('%')
            ->doesntExpectOutputToContain('Found')
            ->assertFailed();
    });
});

describe('agent detection', function (): void {
    beforeEach(function (): void {
        putFile('app/Example.php', "<?php __('Hello');");
    });

    it('prints only the json result when run by an agent', function (string $variable, string $value): void {
        putenv("{$variable}={$value}");

        artisan(AuditTranslations::class, ['--ansi' => true])
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->doesntExpectOutputToContain('%')
            ->doesntExpectOutputToContain('Found')
            ->assertFailed();
    })->with([
        'AI_AGENT' => ['AI_AGENT', 'claude-code'],
        'known agent variable' => ['CLAUDECODE', '1'],
    ]);

    it('prints the json result whatever the display format when run by an agent', function (): void {
        putenv('CLAUDECODE=1');
        config(['translation-audit.display_format' => 'table']);

        artisan(AuditTranslations::class, ['--display-format' => 'table'])
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->assertFailed();
    });

    it('does not warn when a heavy path is set for scan when run by an agent', function (): void {
        putenv('CLAUDECODE=1');
        config(['translation-audit.scan_paths' => ['vendor']]);

        artisan(AuditTranslations::class)
            ->doesntExpectOutputToContain('is set for scan')
            ->assertSuccessful();
    });

    it('prints only the json result when run by an agent even when the for-agent option is false', function (): void {
        putenv('CLAUDECODE=1');

        artisan(AuditTranslations::class, ['--for-agent' => 'false'])
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->doesntExpectOutputToContain('Found')
            ->assertFailed();
    });

    it('prints the result as usual when no agent is detected', function (): void {
        putenv('AI_AGENT=');

        artisan(AuditTranslations::class)
            ->expectsOutput('    EN, IT  Hello')
            ->expectsOutput('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    });
});

describe('summary', function (): void {
    beforeEach(function (): void {
        putFile('app/Example.php', "<?php __('Hello');");
    });

    it('prints the summary by default', function (): void {
        artisan(AuditTranslations::class)
            ->expectsOutput('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    });

    it('hides the summary with the no-summary option', function (?string $value): void {
        artisan(AuditTranslations::class, ['--no-summary' => $value])
            ->expectsOutput('    EN, IT  Hello')
            ->doesntExpectOutputToContain('Found 1 key with missing translations')
            ->assertFailed();
    })->with([null, 'true']);

    it('hides the summary when it is disabled in the config', function (): void {
        config(['translation-audit.disable_summary' => true]);

        artisan(AuditTranslations::class)
            ->expectsOutput('    EN, IT  Hello')
            ->doesntExpectOutputToContain('Found 1 key with missing translations')
            ->assertFailed();
    });

    it('prints the summary when the no-summary option overrides the config', function (): void {
        config(['translation-audit.disable_summary' => true]);

        artisan(AuditTranslations::class, ['--no-summary' => 'false'])
            ->expectsOutput('Found 1 key with missing translations in 1 file.')
            ->assertFailed();
    });

    it('prints only the result with the progress bar and the summary hidden', function (): void {
        auditAsJson(['--no-progress' => true, '--no-summary' => true])
            ->expectsOutput(resultJson(['app/Example.php' => ['Hello' => ['en', 'it']]]))
            ->doesntExpectOutputToContain('app/Example.php ')
            ->doesntExpectOutputToContain('Found')
            ->assertFailed();
    });

    it('fails when the no-summary option is not a boolean', function (): void {
        artisan(AuditTranslations::class, ['--no-summary' => 'maybe'])
            ->expectsOutputToContain('The --no-summary option accepts only true or false.')
            ->assertExitCode(Command::INVALID);
    });
});

describe('output streams', function (): void {
    it('prints the result on the standard output and the summary on the error output', function (string $format, string $result): void {
        putFile('app/Example.php', "<?php __('Hello');");

        $output = auditWithSeparateOutputs(['--display-format' => $format]);

        expect($output['exit_code'])->toBe(Command::FAILURE)
            ->and($output['output'])->toContain($result)
            ->and($output['output'])->not->toContain('Found')
            ->and($output['error_output'])->toBe(PHP_EOL.'Found 1 key with missing translations in 1 file.'.PHP_EOL);
    })->with('display formats');

    it('prints nothing on the standard output when no translation is missing', function (string $format): void {
        $output = auditWithSeparateOutputs(['--display-format' => $format]);

        expect($output['exit_code'])->toBe(Command::SUCCESS)
            ->and($output['output'])->toBeEmpty()
            ->and($output['error_output'])->toBe('No missing translations found.'.PHP_EOL);
    })->with(['list', 'table']);

    it('prints an empty json object on the standard output when no translation is missing', function (): void {
        $output = auditWithSeparateOutputs(['--display-format' => 'json']);

        expect($output['exit_code'])->toBe(Command::SUCCESS)
            ->and($output['output'])->toBe(resultJson([]).PHP_EOL)
            ->and($output['error_output'])->toBe('No missing translations found.'.PHP_EOL);
    });

    it('prints nothing on the error output for an agent', function (bool $is_missing): void {
        config(['translation-audit.scan_paths' => ['app/**/*.php', 'vendor'], 'translation-audit.save_path' => base_path('audits')]);
        putFile('app/Example.php', $is_missing ? "<?php __('Hello');" : '<?php');

        $output = auditWithSeparateOutputs(['--for-agent' => true, '--save' => true], decorated_error_output: true);

        expect($output['output'])->toBeJson()
            ->and($output['error_output'])->toBeEmpty();
    })->with(['missing translations' => true, 'no missing translation' => false]);

    it('prints the heavy path warnings on the error output', function (): void {
        config(['translation-audit.scan_paths' => ['vendor']]);

        $output = auditWithSeparateOutputs();

        expect($output['output'])->toBeEmpty()
            ->and($output['error_output'])->toContain("The '".base_path('vendor')."' is set for scan.");
    });

    it('prints the path of the saved result on the error output', function (): void {
        config(['translation-audit.save_path' => base_path('audits')]);

        $output = auditWithSeparateOutputs(['--save' => true, '--save-name' => 'audit']);

        expect($output['output'])->toBeEmpty()
            ->and($output['error_output'])->toContain('Audit result saved: '.base_path('audits').DIRECTORY_SEPARATOR.'audit.json');
    });

    it('prints the configuration errors on the error output', function (): void {
        config(['translation-audit.scan_paths' => []]);

        $output = auditWithSeparateOutputs();

        expect($output['exit_code'])->toBe(Command::INVALID)
            ->and($output['output'])->toBeEmpty()
            ->and($output['error_output'])->toBe('Specify which paths to scan in the "scan_paths" config.'.PHP_EOL);
    });

    it('prints the errors on the error output for an agent', function (Closure $configure, int $exit_code, string $error): void {
        $configure();

        $output = auditWithSeparateOutputs(['--for-agent' => true]);

        expect($output['exit_code'])->toBe($exit_code)
            ->and($output['output'])->toBeEmpty()
            ->and($output['error_output'])->toContain($error);
    })->with([
        'invalid option' => [fn () => config(['translation-audit.disable_summary' => 'maybe']), Command::INVALID, 'disable_summary'],
        'unparsable file' => [fn () => putFile('app/Broken.php', '<?php function ('), Command::FAILURE, 'Unable to scan app/Broken.php: Syntax error'],
    ]);

    it('prints the scan errors on the error output', function (): void {
        putFile('app/Broken.php', '<?php function (');

        $output = auditWithSeparateOutputs();

        expect($output['exit_code'])->toBe(Command::FAILURE)
            ->and($output['output'])->toBeEmpty()
            ->and($output['error_output'])->toContain('Unable to scan app/Broken.php: Syntax error');
    });
});
