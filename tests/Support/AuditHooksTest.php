<?php

declare(strict_types=1);

use TranslationAudit\Console\Commands\AuditTranslations;
use TranslationAudit\Console\Commands\PurgeUnusedTranslations;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\AuditHooks;

final class AuditHooksTestBeforeHook {
    public function before(): string {
        return 'before';
    }
}

final class AuditHooksTestAfterHook {
    public function after(): string {
        return 'after';
    }
}

it('accepts the hook classes defining a before or an after method, running on every command or on the mapped ones', function (array $config): void {
    expect(AuditHooks::fromConfig($config))->toBeInstanceOf(AuditHooks::class);
})->with([
    'no hooks' => [[]],
    'hooks on every command' => [[AuditHooksTestBeforeHook::class, AuditHooksTestAfterHook::class]],
    'hook on a command' => [[AuditHooksTestBeforeHook::class => AuditTranslations::class]],
    'hook on a list of commands' => [[AuditHooksTestBeforeHook::class => [AuditTranslations::class, PurgeUnusedTranslations::class]]],
]);

it('fails when the config contains something other than a hook class', function (array $config): void {
    AuditHooks::fromConfig($config);
})->with([
    'missing class' => [['App\Translations\MissingHook']],
    'class without hook methods' => [[stdClass::class]],
    'hook instance' => [[new AuditHooksTestBeforeHook]],
    'integer' => [[1]],
    'mapped class without hook methods' => [[stdClass::class => AuditTranslations::class]],
])->throws(InvalidConfigException::class, 'The "hooks" config must contain only classes defining a before or an after method.');

it('fails when a hook class is not mapped to the command classes', function (mixed $commands): void {
    AuditHooks::fromConfig([AuditHooksTestBeforeHook::class => $commands]);
})->with([
    'missing class' => 'App\Console\Commands\MissingCommand',
    'class not extending the audit command' => stdClass::class,
    'empty list' => [[]],
    'map of commands' => [['audit' => AuditTranslations::class]],
    'invalid command in the list' => [[AuditTranslations::class, 1]],
    'integer' => 1,
])->throws(InvalidConfigException::class, 'The "hooks" config must map each hook class to the command class, or the list of command classes, extending TranslationAudit\Console\Commands\AuditCommand to run on.');
