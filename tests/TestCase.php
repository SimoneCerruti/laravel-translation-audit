<?php

declare(strict_types=1);

namespace TranslationAudit\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Orchestra\Testbench\TestCase as Orchestra;
use TranslationAudit\TranslationAuditServiceProvider;

abstract class TestCase extends Orchestra {
    /** Throwaway application base path, isolated per test so parallel runs never share fixtures. */
    protected string $temporary_base_path;

    protected function setUp(): void {
        parent::setUp();

        $this->temporary_base_path = sys_get_temp_dir().'/translation-audit-'.bin2hex(random_bytes(8));

        File::ensureDirectoryExists($this->temporary_base_path);

        $this->app->setBasePath($this->temporary_base_path);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
        Lang::clearResolvedInstance('translator');
    }

    protected function tearDown(): void {
        File::deleteDirectory($this->temporary_base_path);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array {
        return [
            TranslationAuditServiceProvider::class,
        ];
    }
}
