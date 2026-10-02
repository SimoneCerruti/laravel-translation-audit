<?php

declare(strict_types=1);

namespace TranslationAudit\Data;

use TranslationAudit\Enums\MessageSeverity;

/** A message to display in the console, styled by its severity. */
readonly class DisplayMessage {
    /**
     * @param  string  $text  The plain text of the message, which can span multiple lines.
     */
    public function __construct(
        public string $text,
        public MessageSeverity $severity,
    ) {}
}
