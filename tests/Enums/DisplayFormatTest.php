<?php

declare(strict_types=1);

use TranslationAudit\Actions\PrintResultAsJson;
use TranslationAudit\Actions\PrintResultAsList;
use TranslationAudit\Actions\PrintResultAsTable;
use TranslationAudit\Enums\DisplayFormat;

it('returns the printer of the format', function (DisplayFormat $format, string $printer): void {
    expect($format->getPrinter())->toBeInstanceOf($printer);
})->with([
    'json' => [DisplayFormat::Json, PrintResultAsJson::class],
    'list' => [DisplayFormat::List, PrintResultAsList::class],
    'table' => [DisplayFormat::Table, PrintResultAsTable::class],
]);
