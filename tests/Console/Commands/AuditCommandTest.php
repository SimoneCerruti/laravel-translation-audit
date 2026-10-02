<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use TranslationAudit\Console\Commands\AuditCommand;

it('returns from each command the result named after it', function (): void {
    $commands = array_filter(Artisan::all(), fn (object $command): bool => $command instanceof AuditCommand);

    expect($commands)->not->toBeEmpty();

    foreach ($commands as $command) {
        $return_type = new ReflectionMethod($command, 'perform')->getReturnType();

        expect($return_type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($return_type->getName())->toBe('TranslationAudit\Results\\'.class_basename($command).'Result');
    }
});
