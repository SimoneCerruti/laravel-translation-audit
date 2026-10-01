<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

readonly class UsedTranslationKey {
    /**
     * @param  string  $file  The path of the file using the key, relative to the project root.
     * @param  non-falsy-string  $value  The translation key.
     */
    public function __construct(
        public string $file,
        public string $value,
    ) {}
}
