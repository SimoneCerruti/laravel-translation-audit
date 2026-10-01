<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use TranslationAudit\Data\AuditResult;

final class SaveAuditResult {
    /**
     * Save the audit result in the directory, naming the file after the name pattern and the format.
     * The name supports the following patterns, wrapped in curly braces: `now:<format>` for the current date, `random:<length>` for random alphanumeric characters.
     *
     * @return non-falsy-string The path of the saved file.
     */
    public function handle(AuditResult $result, string $format, string $directory, string $name): string {
        $content = match ($format) {
            'json' => $this->toJson($result),
            default => throw new InvalidArgumentException("Unsupported save format '{$format}'."),
        };

        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR."{$this->resolveName($name)}.{$format}";

        File::ensureDirectoryExists(\dirname($path));
        File::put($path, $content);

        return $path;
    }

    private function toJson(AuditResult $result): string {
        $sections = ['missing' => $result->missingByFile()];

        if ($result->unused instanceof Collection) {
            $sections['unused'] = $result->unusedByLocale();
        }

        // turning empty sections into stdClass in order to encode them as empty objects, like the non-empty ones.
        return json_encode(array_map(fn (Collection $section): Collection|stdClass => $section->isEmpty() ? new stdClass : $section, $sections), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function resolveName(string $name): string {
        return Str::replaceMatches('/\{(\w+)(?::([^}]*))?\}/', fn (array $m): string => match ($m[1]) {
            'now' => str_replace(['\\', '/', ':'], '-', Carbon::now()->format($m[2] ?? 'Y-m-d')),
            'random' => Str::random((int) (($n = $m[2] ?? 8) < 0 ? 8 : $n)),
            default => $m[0],
        }, $name);
    }
}
