<?php

declare(strict_types=1);

namespace TranslationAudit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('translation:audit')]
#[Description('Audit your app for missing or unused translations.')]
class AuditTranslations extends Command {
    public function handle(): int {
        $this->line('TranslationAudit placeholder command executed.');

        return self::SUCCESS;
    }
}
