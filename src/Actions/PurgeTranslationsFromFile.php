<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use Throwable;
use TranslationAudit\Support\PhpTranslationFile;

use function Safe\json_decode;
use function Safe\preg_replace_callback;
use function Safe\tempnam;

final class PurgeTranslationsFromFile {
    /**
     * Remove the translation keys from the JSON or PHP translation file, rewriting it only when a translation was removed.
     * Only the string translations are removed. In the PHP translation files only their items are removed, along with the nested arrays left empty, leaving the rest of the source untouched.
     *
     * @param  string  $file  The path of the translation file, relative to the project root.
     * @param  Collection<int, string>  $keys  The keys keyed like the translator expects them, the group of the PHP translation files included.
     * @return Collection<string, string> The removed translations, keyed like the given keys.
     *
     * @throws InvalidArgumentException When the file is neither a JSON nor a PHP translation file.
     */
    public function handle(string $file, Collection $keys): Collection {
        $path = base_path($file);

        return match (File::extension($path)) {
            'json' => $this->purgeJsonFile($path, $keys),
            'php' => $this->purgePhpFile($path, $keys),
            default => throw new InvalidArgumentException("Unable to purge {$file}: only the JSON and PHP translation files are supported."),
        };
    }

    /**
     * @param  Collection<int, string>  $keys
     * @return Collection<string, string>
     */
    private function purgeJsonFile(string $path, Collection $keys): Collection {
        $contents = File::get($path);
        $lines = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $lines = \is_array($lines) ? $lines : [];
        /** @var Collection<string, string> $purged */
        $purged = new Collection;

        // JSON translation keys are flat and can contain dots, so they are looked up as they are.
        foreach ($keys as $key) {
            if (! \is_string($lines[$key] ?? null)) {
                continue;
            }

            $purged->put($key, $lines[$key]);
            unset($lines[$key]);
        }

        if ($purged->isNotEmpty()) {
            // turning an empty file into stdClass in order to encode it as an empty object.
            $json = json_encode($lines === [] ? new stdClass : $lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            File::put($path, $this->withJsonFormatting($json, $contents));
        }

        return $purged;
    }

    /**
     * Format the pretty printed JSON like the original contents: with the same indentation, four spaces when it has none, and a final newline only when it had one.
     * Encoded strings can't hold a newline, so the leading whitespace of every line is indentation.
     */
    private function withJsonFormatting(string $json, string $contents): string {
        $indentation = Str::match('/\n([ \t]+)\S/', $contents) ?: '    ';
        $json = preg_replace_callback('/^(?: {4})+/m', fn (array $match): string => str_repeat($indentation, intdiv(\strlen($match[0]), 4)), $json);

        return str_ends_with($contents, "\n") ? $json."\n" : $json;
    }

    /**
     * Remove the string translations from the source of the PHP translation file, leaving the rest of the source untouched.
     * Nothing is removed from a file that does not return a literal array, or when the purged source would not hold the same translations without the removed ones.
     *
     * @param  Collection<int, string>  $keys
     * @return Collection<string, string>
     */
    private function purgePhpFile(string $path, Collection $keys): Collection {
        $lines = File::getRequire($path);
        $lines = \is_array($lines) ? Arr::dot($lines) : [];
        $file = PhpTranslationFile::parse(File::get($path));
        $group = $this->getGroup($path);
        /** @var Collection<string, string> $purged */
        $purged = new Collection;

        if (! $file instanceof PhpTranslationFile) {
            return $purged;
        }

        foreach ($keys as $key) {
            if (! str_starts_with($key, "{$group}.")) {
                continue;
            }

            $line_key = Str::after($key, "{$group}.");
            $value = $lines[$line_key] ?? null;

            if (! \is_string($value) || ! $file->has($line_key)) {
                continue;
            }

            $purged->put($key, $value);
        }

        if ($purged->isEmpty()) {
            return $purged;
        }

        $line_keys = $purged->keys()->map(fn (string $key): string => Str::after($key, "{$group}."));
        $source = $file->withoutKeys($line_keys);

        $expected_lines = array_diff_key($lines, $line_keys->flip()->all());

        if (! $this->evaluatesTo($source, $expected_lines)) {
            return new Collection;
        }

        File::put($path, $source);

        return $purged;
    }

    /**
     * Whether the PHP source, evaluated from a temporary file, returns the lines, like Arr::dot names them.
     *
     * @param  array<array-key, mixed>  $lines
     */
    private function evaluatesTo(string $source, array $lines): bool {
        $temporary_path = tempnam(sys_get_temp_dir(), 'translation-audit-');

        try {
            File::put($temporary_path, $source);
            $purged_lines = File::getRequire($temporary_path);

            return \is_array($purged_lines) && Arr::dot($purged_lines) === $lines;
        } catch (Throwable) {
            return false;
        } finally {
            File::delete($temporary_path);
        }
    }

    /** The group of a PHP translation file: its path relative to the locale directory, without the extension. */
    private function getGroup(string $path): string {
        $relative_path = Str::after(str_replace('\\', '/', $path), str_replace('\\', '/', lang_path()).'/');

        return Str::of($relative_path)->after('/')->beforeLast('.php')->toString();
    }
}
