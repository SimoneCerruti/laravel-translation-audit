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
     * A file that cannot be scanned fails the scan, unless a callback for the skipped files is given: then it is skipped, without any of its keys.
     *
     * @param  Collection<int, SplFileInfo>  $files
     * @param  TranslationCalls  $translation_calls  The custom translation calls to scan for, besides Laravel's own.
     * @param  (Closure(SplFileInfo, int): void)|null  $on_file_scanned  Called after each file, scanned or skipped, with the file and its index.
     * @param  (Closure(SplFileInfo, RuntimeException): void)|null  $on_file_skipped  Called for each file that cannot be scanned, with the file and the error naming it.
     * @return Collection<int, UsedTranslationKey>
     *
     * @throws RuntimeException Naming the file that cannot be scanned, without a callback for the skipped files.
     */
    public function handle(Collection $files, TranslationCalls $translation_calls, ?Closure $on_file_scanned = null, ?Closure $on_file_skipped = null): Collection {
        $translation_keys = new Collection;

        foreach ($files as $index => $file) {
            $relative_path = str_replace('\\', '/', $file->getRelativePathname());

            try {
                foreach ($this->find_translation_keys_in_file->handle($file, $translation_calls) as $key) {
                    $translation_keys->push(new UsedTranslationKey($relative_path, $key));
                }
            } catch (Exception $e) {
                $error = new RuntimeException("Unable to scan {$relative_path}: {$e->getMessage()}", $e->getCode(), previous: $e);

                if (! $on_file_skipped instanceof Closure) {
                    throw $error;
                }

                $on_file_skipped($file, $error);
            }

            $on_file_scanned?->__invoke($file, $index);
        }

        return $translation_keys;
    }
}
