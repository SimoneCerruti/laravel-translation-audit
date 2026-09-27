<?php

declare(strict_types=1);

it('merges the package config', function () {
    expect(config('translation-audit.scan_paths'))->toBe([app_path(), resource_path('views')]);
    expect(config('translation-audit.ignore_paths'))->toBe([]);
    expect(config('translation-audit.ignore_langs'))->toBe([]);
    expect(config('translation-audit.supported_locales'))->toBe(['auto']);
});

it('registers the artisan command', function () {
    $this->artisan('translation:audit')->assertSuccessful();
});
