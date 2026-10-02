<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use TranslationAudit\Enums\SaveFormat;

/** Where and in which format to save the audit result. */
readonly class SaveTarget {
    /**
     * @param  non-empty-string  $directory
     * @param  non-empty-string  $name  The name of the file without the extension. It supports the following patterns, wrapped in curly braces: `now:<format>` for the current date, `random:<length>` for random alphanumeric characters.
     */
    public function __construct(
        public SaveFormat $format,
        public string $directory,
        public string $name,
    ) {}

    /**
     * The path of the file in the directory, named after the name with its patterns resolved and the extension of the format.
     * The patterns are resolved on each call, so the path of the date and random patterns changes from one call to the next.
     *
     * @return non-falsy-string
     */
    public function resolvePath(): string {
        return rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR."{$this->resolveName()}.{$this->format->value}";
    }

    private function resolveName(): string {
        return Str::replaceMatches('/\{(\w+)(?::([^}]*))?\}/', fn (array $m): string => match ($m[1]) {
            'now' => str_replace(['\\', '/', ':'], '-', Carbon::now()->format($m[2] ?? 'Y-m-d')),
            'random' => Str::random((int) (($n = $m[2] ?? 8) < 0 ? 8 : $n)),
            default => $m[0],
        }, $this->name);
    }
}
