<?php

declare(strict_types=1);

namespace TranslationAudit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TranslationAudit\TranslationAuditServiceProvider;

abstract class TestCase extends Orchestra {
    protected function getPackageProviders($app): array {
        return [
            TranslationAuditServiceProvider::class,
        ];
    }
}
