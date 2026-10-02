<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use stdClass;
use TranslationAudit\Data\AuditResult;
use TranslationAudit\Data\SaveTarget;
use TranslationAudit\Enums\SaveFormat;

final class SaveAuditResult {
    /**
     * Save the audit result in the format of the target, at the path it resolves to.
     *
     * @return non-falsy-string The path of the saved file.
     */
    public function handle(AuditResult $result, SaveTarget $target): string {
        $content = match ($target->format) {
            SaveFormat::Json => $this->makeJsonResult($result),
        };

        $path = $target->resolvePath();

        File::ensureDirectoryExists(\dirname($path));
        File::put($path, $content);

        return $path;
    }

    private function makeJsonResult(AuditResult $result): string {
        $sections = ['missing' => $result->missingByFile()];

        if ($result->unused instanceof Collection) {
            $sections['unused'] = $result->unusedByLocale();
        }

        // turning empty sections into stdClass in order to encode them as empty objects, like the non-empty ones.
        return json_encode(array_map(fn (Collection $section): Collection|stdClass => $section->isEmpty() ? new stdClass : $section, $sections), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
