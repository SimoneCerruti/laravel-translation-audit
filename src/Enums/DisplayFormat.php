<?php

declare(strict_types=1);

namespace TranslationAudit\Enums;

use TranslationAudit\Actions\Contracts\ResultPrinter;
use TranslationAudit\Actions\PrintResultAsJson;
use TranslationAudit\Actions\PrintResultAsList;
use TranslationAudit\Actions\PrintResultAsTable;

/** The formats in which the audit result can be displayed in the console. */
enum DisplayFormat: string {
    case Json = 'json';
    case List = 'list';
    case Table = 'table';

    /** Resolve from the container the printer of the audit result in this format. */
    public function getPrinter(): ResultPrinter {
        return app(match ($this) {
            self::Json => PrintResultAsJson::class,
            self::List => PrintResultAsList::class,
            self::Table => PrintResultAsTable::class,
        });
    }
}
