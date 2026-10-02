<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

use function Safe\json_decode;

final class PurgeTranslationsFromFile {
    /**
     * Remove the translation keys from the JSON or PHP translation file, rewriting it only when a translation was removed.
     * Only the string translations are removed. The PHP translation files lose their comments and formatting, and the nested arrays left empty are removed too.
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
        $lines = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
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
            File::put($path, json_encode($lines === [] ? new stdClass : $lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }

        return $purged;
    }

    /**
     * @param  Collection<int, string>  $keys
     * @return Collection<string, string>
     */
    private function purgePhpFile(string $path, Collection $keys): Collection {
        $lines = File::getRequire($path);
        $lines = \is_array($lines) ? $lines : [];
        $group = $this->getGroup($path);
        /** @var Collection<string, string> $purged */
        $purged = new Collection;

        foreach ($keys as $key) {
            if (! str_starts_with($key, "{$group}.")) {
                continue;
            }

            $line_key = Str::after($key, "{$group}.");
            $value = Arr::get($lines, $line_key);

            if (! \is_string($value)) {
                continue;
            }

            $purged->put($key, $value);
            Arr::forget($lines, $line_key);
            $this->forgetEmptyParents($lines, $line_key);
        }

        if ($purged->isNotEmpty()) {
            File::put($path, "<?php\n\nreturn {$this->exportArray($lines)};\n");
        }

        return $purged;
    }

    /**
     * Remove the parents of the forgotten key left empty, from the deepest one.
     *
     * @param  array<array-key, mixed>  $lines
     */
    private function forgetEmptyParents(array &$lines, string $key): void {
        $parent = $key;

        while (str_contains($parent, '.')) {
            $parent = Str::beforeLast($parent, '.');

            if (Arr::get($lines, $parent) !== []) {
                return;
            }

            Arr::forget($lines, $parent);
        }
    }

    /** The group of a PHP translation file: its path relative to the locale directory, without the extension. */
    private function getGroup(string $path): string {
        $relative_path = Str::after(str_replace('\\', '/', $path), str_replace('\\', '/', lang_path()).'/');

        return Str::of($relative_path)->after('/')->beforeLast('.php')->toString();
    }

    /**
     * Export the array as PHP code using the short array syntax, indented by four spaces at each level.
     * Only the scalars are exported with var_export, since it would use the long array syntax.
     *
     * @param  array<array-key, mixed>  $array
     */
    private function exportArray(array $array, int $depth = 0): string {
        if ($array === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $is_list = array_is_list($array);
        $items = [];

        foreach ($array as $key => $value) {
            $exported_value = \is_array($value) ? $this->exportArray($value, $depth + 1) : var_export($value, true);

            $items[] = $is_list ? "{$indent}{$exported_value}," : $indent.var_export($key, true)." => {$exported_value},";
        }

        return "[\n".implode("\n", $items)."\n".str_repeat('    ', $depth).']';
    }
}
