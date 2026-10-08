<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Facades\File;
use TranslationAudit\Data\SaveTarget;
use TranslationAudit\Enums\SaveFormat;
use TranslationAudit\Results\AuditTranslationsResult;

final class SaveAuditResult {
    /**
     * Save the audit result in the format of the target, at the path it resolves to.
     *
     * @return non-falsy-string The path of the saved file.
     */
    public function handle(AuditTranslationsResult $result, SaveTarget $target): string {
        $content = match ($target->format) {
            SaveFormat::Json => $this->makeJsonResult($result),
        };

        $path = $target->resolvePath();

        File::ensureDirectoryExists(\dirname($path));
        File::put($path, $content);

        return $path;
    }

    private function makeJsonResult(AuditTranslationsResult $result): string {
        return json_encode($result->jsonSections(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
