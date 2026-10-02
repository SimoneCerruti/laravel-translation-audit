<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command;
use TranslationAudit\Console\Commands\PurgeUnusedTranslations;

use function Pest\Laravel\artisan;

const UNUSED_JSON = '{"unused":{"en":{"lang/en.json":{"Bye":"Bye"}},"it":{"lang/it/admin/users.php":{"admin/users.title":"Utenti"},"lang/it/messages.php":{"messages.old":"Vecchio","messages.nested.unused":"Inutilizzato"}}}}';

beforeEach(function (): void {
    config(['translation-audit.supported_locales' => ['en', 'it']]);

    putJsonTranslations('en', ['Hello' => 'Hello', 'Bye' => 'Bye']);
    putJsonTranslations('it', ['Hello' => 'Ciao']);
    putFile('lang/it/messages.php', "<?php return ['welcome' => 'Benvenuto', 'old' => 'Vecchio', 'nested' => ['used' => 'Usato', 'unused' => 'Inutilizzato']];");
    putFile('lang/it/admin/users.php', "<?php return ['title' => 'Utenti'];");
    putFile('lang/it/validation.php', "<?php return ['required' => 'Obbligatorio'];");
    putFile('app/Example.php', "<?php __('Hello'); __('messages.welcome'); __('messages.nested.used');");
});

describe('dry run', function (): void {
    it('lists the unused translations without removing them', function (?string $value): void {
        $files = ['lang/en.json', 'lang/it.json', 'lang/it/messages.php', 'lang/it/admin/users.php'];
        $contents = array_map(fn (string $file): string => File::get(base_path($file)), $files);

        purgeAsJson(['--dry-run' => $value])
            ->expectsOutput(UNUSED_JSON)
            ->expectsOutput('4 unused translations would be purged.')
            ->assertSuccessful();

        expect(array_map(fn (string $file): string => File::get(base_path($file)), $files))->toBe($contents);
    })->with([null, 'true']);

    it('counts a single unused translation in the singular', function (): void {
        config(['translation-audit.supported_locales' => ['en']]);

        purgeAsJson(['--dry-run' => true])
            ->expectsOutput('1 unused translation would be purged.')
            ->assertSuccessful();
    });

    it('fails when the dry run option is not a boolean', function (): void {
        artisan(PurgeUnusedTranslations::class, ['--dry-run' => 'yes'])
            ->expectsOutputToContain('The --dry-run option accepts only true or false.')
            ->assertExitCode(Command::INVALID);
    });
});

describe('purge', function (): void {
    it('removes the unused translations from the translation files, listing them', function (array $parameters): void {
        purgeAsJson($parameters)
            ->expectsOutput(UNUSED_JSON)
            ->expectsOutput('4 unused translations purged.')
            ->assertSuccessful();

        expect(File::getRequire(lang_path('it/messages.php')))->toBe(['welcome' => 'Benvenuto', 'nested' => ['used' => 'Usato']])
            ->and(File::getRequire(lang_path('it/admin/users.php')))->toBe([])
            ->and(json_decode(File::get(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR))->toBe(['Hello' => 'Hello'])
            ->and(json_decode(File::get(lang_path('it.json')), true, flags: JSON_THROW_ON_ERROR))->toBe(['Hello' => 'Ciao']);
    })->with([
        'without the dry run option' => [[]],
        'with the dry run option disabled' => [['--dry-run' => 'false']],
    ]);

    it('finds no unused translation once they are purged', function (): void {
        purgeAsJson()->assertSuccessful();

        purgeAsJson()
            ->expectsOutput('{"unused":{}}')
            ->expectsOutput('No unused translations found.')
            ->assertSuccessful();
    });

    it('does not rewrite the translation files without unused translations', function (): void {
        $contents = File::get(lang_path('it.json'));

        purgeAsJson()->assertSuccessful();

        expect(File::get(lang_path('it.json')))->toBe($contents);
    });

    it('leaves the files matching the unused ignore paths untouched', function (): void {
        config(['translation-audit.unused_ignore_paths' => ['lang/*/messages.php', 'lang/*/admin/*.php', 'lang/*/validation.php']]);
        $contents = File::get(lang_path('it/messages.php'));

        purgeAsJson()
            ->expectsOutput('{"unused":{"en":{"lang/en.json":{"Bye":"Bye"}}}}')
            ->expectsOutput('1 unused translation purged.')
            ->assertSuccessful();

        expect(File::get(lang_path('it/messages.php')))->toBe($contents)
            ->and(File::getRequire(lang_path('it/admin/users.php')))->toBe(['title' => 'Utenti'])
            ->and(File::getRequire(lang_path('it/validation.php')))->toBe(['required' => 'Obbligatorio']);
    });

    it('leaves the ignored locales and keys untouched', function (): void {
        config(['translation-audit.ignore_locales' => ['en'], 'translation-audit.ignore_keys' => ['messages.old', 'admin/users.title' => ['it']]]);

        purgeAsJson()
            ->expectsOutput('{"unused":{"it":{"lang/it/messages.php":{"messages.nested.unused":"Inutilizzato"}}}}')
            ->assertSuccessful();

        expect(File::getRequire(lang_path('it/messages.php')))->toBe(['welcome' => 'Benvenuto', 'old' => 'Vecchio', 'nested' => ['used' => 'Usato']])
            ->and(File::getRequire(lang_path('it/admin/users.php')))->toBe(['title' => 'Utenti'])
            ->and(json_decode(File::get(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR))->toBe(['Hello' => 'Hello', 'Bye' => 'Bye']);
    });
});
