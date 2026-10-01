<?php

declare(strict_types=1);

namespace TranslationAudit\Enums;

/** The formats in which the audit result can be saved, backed by the extension of the saved file. */
enum SaveFormat: string {
    case Json = 'json';
}
