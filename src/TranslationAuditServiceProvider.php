<?php

declare(strict_types=1);

namespace TranslationAudit;

use Illuminate\Support\ServiceProvider;
use TranslationAudit\Console\Commands\AuditTranslations;

class TranslationAuditServiceProvider extends ServiceProvider {
    /**
     * Register any application services.
     */
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/translation-audit.php', 'translation-audit');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/translation-audit.php' => config_path('translation-audit.php'),
        ], ['laravel-translation-audit', 'laravel-translation-audit-config']);

        $this->publishes([
            __DIR__.'/../workflows/audit-translations.yml' => base_path('.github/workflows/audit-translations.yml'),
        ], ['laravel-translation-audit', 'laravel-translation-audit-workflow']);

        $this->commands([
            AuditTranslations::class,
        ]);
    }
}
