<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Closure;
use Exception;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Support\TranslationCalls;

final readonly class ScanFilesForTranslationKeys {
    public function __construct(private FindTranslationKeysInFile $find_translation_keys_in_file) {}

    /**
     * Scan the files for the translation keys they use, with the file path relative to the project root.
     *
     * @param  Collection<int, SplFileInfo>  $files
     * @param  TranslationCalls  $translation_calls  The custom translation calls to scan for, besides Laravel's own.
     * @param  (Closure(SplFileInfo, int): void)|null  $on_file_scanned  Called after each file with the file and its index.
     * @return Collection<int, UsedTranslationKey>
     *
     * @throws RuntimeException Naming the file that cannot be scanned.
     */
    public function handle(Collection $files, TranslationCalls $translation_calls, ?Closure $on_file_scanned = null): Collection {
        $translation_keys = new Collection;

        foreach ($files as $index => $file) {
            $relative_path = str_replace('\\', '/', $file->getRelativePathname());

            try {
                foreach ($this->find_translation_keys_in_file->handle($file, $translation_calls) as $key) {
                    $translation_keys->push(new UsedTranslationKey($relative_path, $key));
                }
            } catch (Exception $e) {
                throw new RuntimeException("Unable to scan {$relative_path}: {$e->getMessage()}", $e->getCode(), previous: $e);
            }

            $on_file_scanned?->__invoke($file, $index);
        }

        return $translation_keys;
    }
}
