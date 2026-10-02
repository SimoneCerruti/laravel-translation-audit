<?php

declare(strict_types=1);

namespace TranslationAudit\Enums;

/** The formats in which the audit result can be displayed in the console. */
enum DisplayFormat: string {
    case Json = 'json';
    case List = 'list';
    case Table = 'table';
}
