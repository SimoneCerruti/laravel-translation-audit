<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

readonly class Translation {
    /**
     * @param  non-falsy-string  $key
     * @param  string  $file  The path of the file the translation refers to: the file using the key when missing, the translation file when unused.
     * @param  ?string  $value  The translated text, null when the translation is missing.
     */
    public function __construct(
        public string $key,
        public string $locale,
        public string $file,
        public ?string $value = null,
    ) {}
}
