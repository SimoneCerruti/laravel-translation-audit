<?php

declare(strict_types=1);

namespace TranslationAudit\Enums;

/** The severities of the messages displayed in the console, backed by the console style they are displayed with. */
enum MessageSeverity: string {
    case Info = 'info';
    case Warning = 'comment';
    case Error = 'error';
}
