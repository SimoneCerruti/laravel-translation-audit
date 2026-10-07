<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use TranslationAudit\Console\Commands\AuditCommand;
use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Console\Commands\PurgeUnusedTranslations;
use TranslationAudit\Results\AuditTranslationsResult;
use TranslationAudit\Results\Contracts\Result;

use function Pest\Laravel\artisan;

final class AuditCommandTestHook {
    /** @var list<string> */
    public static array $calls = [];

    private int $runs = 0;

    public function before(AuditCommand $command, Repository $config): void {
        $this->runs++;

        self::$calls[] = "before {$command->getName()} {$config->string('app.name')}";
    }

    public function after(Result $result, AuditCommand $command): void {
        self::$calls[] = "after {$command->getName()} ".$result::class." clean {$result->isClean()} runs {$this->runs}";
    }
}

final class AuditCommandTestAfterHook {
    public function after(AuditTranslationsResult $audit_result): void {
        AuditCommandTestHook::$calls[] = 'after only missing '.$audit_result->missing->count();
    }
}

final class AuditCommandTestSyncHook {
    public function before(): void {
        putJsonTranslations('it', ['Hello' => 'Ciao']);
    }
}

final class AuditCommandTestFailingHook {
    public function before(): never {
        throw new LogicException('The translations service is down.');
    }

    public function after(): void {
        AuditCommandTestHook::$calls[] = 'after failing';
    }
}

final class AuditCommandTestFailingAfterHook {
    public function after(): never {
        throw new LogicException('The notification failed.');
    }
}

it('returns from each command the result named after it', function (): void {
    $commands = array_filter(Artisan::all(), fn (object $command): bool => $command instanceof AuditCommand);

    expect($commands)->not->toBeEmpty();

    foreach ($commands as $command) {
        $return_type = new ReflectionMethod($command, 'perform')->getReturnType();

        expect($return_type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($return_type->getName())->toBe('TranslationAudit\Results\\'.class_basename($command).'Result');
    }
});

describe('hooks', function (): void {
    beforeEach(function (): void {
        AuditCommandTestHook::$calls = [];

        config(['app.name' => 'Workbench', 'translation-audit.supported_locales' => ['en', 'it']]);
        putJsonTranslations('en', ['Hello' => 'Hello']);
        putJsonTranslations('it', []);
        putFile('app/Example.php', "<?php __('Hello');");
    });

    it('fails when the hooks config is not an array', function (): void {
        config(['translation-audit.hooks' => AuditCommandTestHook::class]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('The "hooks" config must be an array.')
            ->assertExitCode(Command::INVALID);
    });

    it('fails when the hooks config contains something other than a hook class', function (): void {
        config(['translation-audit.hooks' => [stdClass::class]]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('The "hooks" config must contain only classes defining a before or an after method.')
            ->assertExitCode(Command::INVALID);
    });

    it('runs the listed hooks on every command, sharing the instance, with the command, the result and the injected dependencies', function (): void {
        config(['translation-audit.hooks' => [AuditCommandTestHook::class]]);

        artisan(AuditTranslations::class)->assertFailed();
        artisan(PurgeUnusedTranslations::class, ['--dry-run' => true])->assertSuccessful();

        expect(AuditCommandTestHook::$calls)->toBe([
            'before translation:audit Workbench',
            'after translation:audit '.AuditTranslationsResult::class.' clean  runs 1',
            'before translation:purge-unused Workbench',
            'after translation:purge-unused TranslationAudit\Results\PurgeUnusedTranslationsResult clean 1 runs 1',
        ]);
    });

    it('runs the mapped hooks only on their commands', function (array|string $commands, array $calls): void {
        config(['translation-audit.hooks' => [AuditCommandTestHook::class => $commands]]);

        artisan(AuditTranslations::class)->assertFailed();
        artisan(PurgeUnusedTranslations::class, ['--dry-run' => true])->assertSuccessful();

        expect(AuditCommandTestHook::$calls)->toBe($calls);
    })->with([
        'command' => [PurgeUnusedTranslations::class, ['before translation:purge-unused Workbench', 'after translation:purge-unused TranslationAudit\Results\PurgeUnusedTranslationsResult clean 1 runs 1']],
        'list of commands' => [[AuditTranslations::class, PurgeUnusedTranslations::class], [
            'before translation:audit Workbench',
            'after translation:audit '.AuditTranslationsResult::class.' clean  runs 1',
            'before translation:purge-unused Workbench',
            'after translation:purge-unused TranslationAudit\Results\PurgeUnusedTranslationsResult clean 1 runs 1',
        ]],
    ]);

    it('runs the hooks in the order of the config, calling only the methods they define', function (): void {
        config(['translation-audit.hooks' => [AuditCommandTestAfterHook::class => AuditTranslations::class, AuditCommandTestHook::class]]);

        artisan(AuditTranslations::class)->assertFailed();

        expect(AuditCommandTestHook::$calls)->toBe([
            'before translation:audit Workbench',
            'after only missing 1',
            'after translation:audit '.AuditTranslationsResult::class.' clean  runs 1',
        ]);
    });

    it('runs the before hooks before the scan', function (): void {
        config(['translation-audit.hooks' => [AuditCommandTestSyncHook::class]]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('No missing translations found.')
            ->assertSuccessful();
    });

    it('fails naming the failing hook, without performing the command nor running the after hooks', function (): void {
        config(['translation-audit.hooks' => [AuditCommandTestFailingHook::class]]);

        artisan(AuditTranslations::class)
            ->expectsOutputToContain('Unable to run the before hook AuditCommandTestFailingHook: The translations service is down.')
            ->doesntExpectOutputToContain('Found 1 key with missing translations')
            ->assertFailed();

        expect(AuditCommandTestHook::$calls)->toBeEmpty();
    });

    it('fails naming the failing after hook, once the result is printed', function (): void {
        config(['translation-audit.hooks' => [AuditCommandTestFailingAfterHook::class]]);

        artisan(PurgeUnusedTranslations::class, ['--dry-run' => true])
            ->expectsOutputToContain('No unused translations found.')
            ->expectsOutputToContain('Unable to run the after hook AuditCommandTestFailingAfterHook: The notification failed.')
            ->assertFailed();
    });
});
