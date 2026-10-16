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

    /** The name with each `{now:<format>}` and `{random:<length>}` pattern replaced, leaving the unknown ones as they are. */
    private function resolveName(): string {
        return Str::replaceMatches('/\{(\w+)(?::([^}]*))?\}/', fn (array $match): string => match ($match[1]) {
            'now' => $this->resolveDate($match[2] ?? 'Y-m-d'),
            'random' => $this->resolveRandom($match[2] ?? '8'),
            default => $match[0],
        }, $this->name);
    }

    /** The current date in the format, with the path separators and the colons replaced by dashes, to keep it a valid file name. */
    private function resolveDate(string $format): string {
        return str_replace(['\\', '/', ':'], '-', Carbon::now()->format($format));
    }

    /** Random alphanumeric characters of the length, 8 when it is negative. */
    private function resolveRandom(string $length): string {
        $length = (int) $length;

        return Str::random($length < 0 ? 8 : $length);
    }
}
