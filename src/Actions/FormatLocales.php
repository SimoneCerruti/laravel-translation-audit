<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

final class FormatLocales {
    /** @param  non-empty-list<string>  $locales */
    public function handle(array $locales): string {
        return implode(', ', array_map(strtoupper(...), $locales));
    }
}
