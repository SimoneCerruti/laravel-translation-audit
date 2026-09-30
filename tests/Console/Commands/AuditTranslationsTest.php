<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use TranslationAudit\Console\Commands\AuditTranslations;

use function Pest\Laravel\artisan;

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

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Greeter.php', 'Hello', 'EN, IT'],
            ])
            ->assertFailed();
    });

    it('detects the locales from the filesystem when configured as auto', function (): void {
        config(['translation-audit.supported_locales' => ['auto']]);
        populateLangDir(files: ['de.json', 'it.json'], directories: ['it', 'fr', 'vendor/some-package/es']);
        putFile('lang/README.md', '');
        putFile('lang/en.php', '<?php return [];');

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Greeter.php', 'Hello', 'DE, FR, IT'],
            ])
            ->assertFailed();
    });

    it('detects only the locales at the top level of the lang directory', function (): void {
        config(['translation-audit.supported_locales' => ['auto']]);
        populateLangDir(files: ['en.json', 'it/fr.json', 'vendor/some-package/es.json'], directories: ['it/de', 'vendor/some-package']);

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Greeter.php', 'Hello', 'EN, IT'],
            ])
            ->assertFailed();
    });

    it('does not audit ignored locales', function (): void {
        config(['translation-audit.ignore_locales' => ['en']]);

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Greeter.php', 'Hello', 'IT'],
            ])
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

describe('file selection', function (): void {
    it('scans only the files matching the scan paths', function (): void {
        putFile('resources/views/welcome.blade.php', "{{ __('Welcome') }}");
        putFile('app/Models/User.php', "<?php __('User');");
        putFile('app/notes.txt', "<?php __('Notes');");
        putFile('routes/web.php', "<?php __('Route');");

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Models/User.php', 'User', 'EN, IT'],
                new TableSeparator,
                ['resources/views/welcome.blade.php', 'Welcome', 'EN, IT'],
            ])
            ->assertFailed();
    });

    it('reports the files sorted by path', function (): void {
        $names = ['Zeta', 'Mu', 'Alpha', 'Omega', 'Beta', 'Kappa', 'Delta', 'Sigma', 'Gamma', 'Eta'];

        foreach ($names as $name) {
            putFile("app/{$name}.php", "<?php __('{$name}');");
        }

        sort($names);

        $rows = collect($names)
            ->flatMap(fn (string $name): array => [new TableSeparator, ["app/{$name}.php", $name, 'EN, IT']])
            ->skip(1)
            ->values()
            ->all();

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], $rows)
            ->assertFailed();
    });

    it('skips the files matching the ignore paths', function (): void {
        config(['translation-audit.ignore_paths' => ['app/Legacy/**']]);
        putFile('app/Legacy/Old.php', "<?php __('Old');");
        putFile('app/New.php', "<?php __('New');");

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/New.php', 'New', 'EN, IT'],
            ])
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
            artisan(AuditTranslations::class, ['--follow-links' => true])
                ->expectsTable(['File', 'Key', 'Missing locales'], [
                    ['resources/views/welcome.blade.php', 'Welcome', 'EN, IT'],
                ])
                ->assertFailed();
        });

        it('follows symbolic links with the follow links option without a value', function (): void {
            artisan(AuditTranslations::class, ['--follow-links' => null])
                ->expectsTable(['File', 'Key', 'Missing locales'], [
                    ['resources/views/welcome.blade.php', 'Welcome', 'EN, IT'],
                ])
                ->assertFailed();
        });

        it('follows symbolic links with the follow links option set to true', function (): void {
            artisan(AuditTranslations::class, ['--follow-links' => 'true'])
                ->expectsTable(['File', 'Key', 'Missing locales'], [
                    ['resources/views/welcome.blade.php', 'Welcome', 'EN, IT'],
                ])
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

            artisan(AuditTranslations::class)
                ->expectsTable(['File', 'Key', 'Missing locales'], [
                    ['resources/views/welcome.blade.php', 'Welcome', 'EN, IT'],
                ])
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

            artisan(AuditTranslations::class, ['--follow-links' => true])
                ->expectsTable(['File', 'Key', 'Missing locales'], [
                    ['app/Module/New.php', 'New', 'EN, IT'],
                    new TableSeparator,
                    ['resources/views/welcome.blade.php', 'Welcome', 'EN, IT'],
                ])
                ->assertFailed();
        });

        it('does not follow the symbolic links matching the ignore links by default', function (): void {
            putFile('shared/vendor/acme/Package.php', "<?php __('Package');");
            putLink('shared/vendor', 'vendor');
            config(['translation-audit.scan_paths' => ['vendor/**/*.php', 'resources/views/**/*blade.php']]);

            artisan(AuditTranslations::class, ['--follow-links' => true])
                ->expectsTable(['File', 'Key', 'Missing locales'], [
                    ['resources/views/welcome.blade.php', 'Welcome', 'EN, IT'],
                ])
                ->assertFailed();
        });
    });
});

describe('translation detection', function (): void {
    it('detects the translation key of a call', function (string $call): void {
        putFile('app/Example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Lang;\nuse Illuminate\\Support\\Facades\\Lang as Translations;\n\n{$call};");

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Example.php', 'messages.welcome', 'EN, IT'],
            ])
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

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['resources/views/welcome.blade.php', 'messages.welcome', 'EN, IT'],
            ])
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

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Example.php', 'Hello', 'IT'],
                ['', 'messages.welcome', 'EN'],
                ['', 'messages.goodbye', 'EN, IT'],
            ])
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

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/Example.php', 'Hello', 'EN, IT'],
            ])
            ->assertFailed();
    });

    it('groups the missing keys by file', function (): void {
        putFile('app/First.php', "<?php __('One'); __('Two');");
        putFile('app/Second.php', "<?php __('One');");
        putJsonTranslations('it', ['One' => 'Uno', 'Two' => 'Due']);

        artisan(AuditTranslations::class)
            ->expectsTable(['File', 'Key', 'Missing locales'], [
                ['app/First.php', 'One', 'EN'],
                ['', 'Two', 'EN'],
                new TableSeparator,
                ['app/Second.php', 'One', 'EN'],
            ])
            ->assertFailed();
    });
});
